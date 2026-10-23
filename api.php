<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_class.php';

/**
 * Envía una respuesta JSON con el código de estado HTTP correspondiente y termina la ejecución.
 */
function sendJson(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sanitiza un texto contra ataques XSS antes de enviarlo al cliente.
 */
function sanitizeOutput(?string $value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formatea y sanitiza una fila de secreto proveniente de la base de datos.
 */
function formatSecretRow(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'title'          => $row['title'] !== null && $row['title'] !== '' ? sanitizeOutput((string) $row['title']) : null,
        'content'        => sanitizeOutput((string) $row['content']),
        'gender'         => sanitizeOutput((string) $row['gender']),
        'age'            => $row['age'] !== null ? (int) $row['age'] : null,
        'country'        => sanitizeOutput((string) ($row['country'] ?? 'Desconocido')),
        'category'       => sanitizeOutput((string) $row['category']),
        'created_at'     => (string) $row['created_at'],
        'likes'          => (int) ($row['likes'] ?? 0),
        'dislikes'       => (int) ($row['dislikes'] ?? 0),
        'comments_count' => (int) ($row['comments_count'] ?? 0),
        'user_vote'      => $row['user_vote'] ?? null,
    ];
}

try {
    $pdo  = Database::getConnection();
    $auth = new Auth($pdo);
} catch (PDOException $e) {
    sendJson(['success' => false, 'error' => 'Error interno de conexión a la base de datos.'], 500);
}

$action   = (string) ($_GET['action'] ?? '');
$rawInput = file_get_contents('php://input');
$input    = is_string($rawInput) && $rawInput !== '' ? (json_decode($rawInput, true) ?? []) : [];

try {
    switch ($action) {
        case 'auth_status':
            sendJson($auth->getStatus(), 200);

        case 'register':
            $result = $auth->register($input);
            sendJson($result['payload'], $result['status']);

        case 'login':
            $result = $auth->login($input);
            sendJson($result['payload'], $result['status']);

        case 'logout':
            $result = $auth->logout();
            sendJson($result['payload'], $result['status']);

        case 'get_secrets':
            $gender    = trim((string) ($_GET['gender'] ?? 'Todos'));
            $country   = trim((string) ($_GET['country'] ?? 'Todos'));
            $category  = trim((string) ($_GET['category'] ?? 'Todas'));
            $minAge    = trim((string) ($_GET['min_age'] ?? ''));
            $maxAge    = trim((string) ($_GET['max_age'] ?? ''));
            $sort      = trim((string) ($_GET['sort'] ?? 'Recientes'));
            $secretId  = isset($_GET['secret_id']) && $_GET['secret_id'] !== '' ? (int) $_GET['secret_id'] : null;

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
            $params = [];

            // Consulta parametrizada que calcula votos y comentarios de forma segura
            $query = "SELECT s.*, 
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'like') AS likes,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'dislike') AS dislikes,
                (SELECT COUNT(*) FROM comments c WHERE c.secret_id = s.id) AS comments_count";

            if ($userId > 0) {
                $query .= ", (SELECT v.type FROM votes v WHERE v.secret_id = s.id AND v.user_id = ? LIMIT 1) AS user_vote";
                $params[] = $userId;
            } else {
                $query .= ", NULL AS user_vote";
            }

            $query .= " FROM secrets s WHERE 1=1";

            // Filtro por ID único (para enlaces directos a un secreto)
            if ($secretId !== null && $secretId > 0) {
                $query .= " AND s.id = ?";
                $params[] = $secretId;
            }

            // Filtro por género (comparación insensible a mayúsculas/minúsculas)
            if ($gender !== '' && $gender !== 'Todos') {
                $query .= " AND LOWER(s.gender) = LOWER(?)";
                $params[] = $gender;
            }

            // Filtro por país
            if ($country !== '' && $country !== 'Todos') {
                $query .= " AND s.country = ?";
                $params[] = $country;
            }

            // Filtro por categoría
            if ($category !== '' && $category !== 'Todas') {
                $query .= " AND s.category = ?";
                $params[] = $category;
            }

            // Filtros de rango de edad
            if ($minAge !== '' && is_numeric($minAge)) {
                $query .= " AND s.age >= ?";
                $params[] = (int) $minAge;
            }
            if ($maxAge !== '' && is_numeric($maxAge)) {
                $query .= " AND s.age <= ?";
                $params[] = (int) $maxAge;
            }

            // Ordenamiento seguro mediante lista blanca
            if ($sort === 'Antiguos') {
                $query .= " ORDER BY s.created_at ASC";
            } elseif ($sort === 'Top') {
                $query .= " ORDER BY (likes - dislikes) DESC, s.created_at DESC";
            } else {
                $query .= " ORDER BY s.created_at DESC";
            }

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $secrets = array_map('formatSecretRow', $rows);
            sendJson(['success' => true, 'secrets' => $secrets], 200);

        case 'get_secret':
            $secretId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($secretId <= 0) {
                sendJson(['success' => false, 'error' => 'ID de secreto inválido.'], 400);
            }

            $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
            $params = [];

            $query = "SELECT s.*, 
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'like') AS likes,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'dislike') AS dislikes,
                (SELECT COUNT(*) FROM comments c WHERE c.secret_id = s.id) AS comments_count";

            if ($userId > 0) {
                $query .= ", (SELECT v.type FROM votes v WHERE v.secret_id = s.id AND v.user_id = ? LIMIT 1) AS user_vote";
                $params[] = $userId;
            } else {
                $query .= ", NULL AS user_vote";
            }

            $query .= " FROM secrets s WHERE s.id = ? LIMIT 1";
            $params[] = $secretId;

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $row = $stmt->fetch();

            if (!$row) {
                sendJson(['success' => false, 'error' => 'El secreto solicitado no existe o fue eliminado.'], 404);
            }

            sendJson(['success' => true, 'secret' => formatSecretRow($row)], 200);

        case 'post_secret':
            if (empty($_SESSION['user_id'])) {
                sendJson(['success' => false, 'error' => 'Debes iniciar sesión para publicar un secreto.'], 401);
            }

            $title    = trim((string) ($input['title'] ?? ''));
            $content  = trim((string) ($input['content'] ?? ''));
            $gender   = trim((string) ($input['gender'] ?? 'Otro'));
            $age      = isset($input['age']) && $input['age'] !== '' ? (int) $input['age'] : null;
            $country  = trim((string) ($input['country'] ?? 'Desconocido'));
            $category = trim((string) ($input['category'] ?? 'Foro Libre'));

            if (mb_strlen($content) < 10) {
                sendJson(['success' => false, 'error' => 'Tu historia debe tener al menos 10 caracteres.'], 400);
            }

            if (mb_strlen($title) > 150) {
                sendJson(['success' => false, 'error' => 'El título no puede superar los 150 caracteres.'], 400);
            }

            if ($age !== null && ($age < 13 || $age > 99)) {
                sendJson(['success' => false, 'error' => 'La edad debe estar entre 13 y 99 años.'], 400);
            }

            $allowedCategories = ['Foro Libre', 'Amor y Pareja', 'Salud Mental', 'Humor'];
            if (!in_array($category, $allowedCategories, true)) {
                $category = 'Foro Libre';
            }

            $allowedGenders = ['Mujer', 'Hombre', 'Otro'];
            if (!in_array($gender, $allowedGenders, true)) {
                $gender = 'Otro';
            }

            $stmt = $pdo->prepare(
                "INSERT INTO secrets (user_id, title, content, gender, age, country, category) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                (int) $_SESSION['user_id'],
                $title !== '' ? $title : null,
                $content,
                $gender,
                $age,
                $country !== '' ? $country : 'Desconocido',
                $category,
            ]);

            $newId = (int) $pdo->lastInsertId();
            sendJson(['success' => true, 'secret_id' => $newId], 201);

        case 'toggle_vote':
            if (empty($_SESSION['user_id'])) {
                sendJson(['success' => false, 'error' => 'Debes iniciar sesión para votar.'], 401);
            }

            $secretId = isset($input['secret_id']) ? (int) $input['secret_id'] : 0;
            $type     = (string) ($input['type'] ?? '');
            $userId   = (int) $_SESSION['user_id'];

            if ($secretId <= 0 || !in_array($type, ['like', 'dislike'], true)) {
                sendJson(['success' => false, 'error' => 'Parámetros de voto inválidos.'], 400);
            }

            // Transacción SQL para garantizar consistencia al alternar el voto
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT type FROM votes WHERE secret_id = ? AND user_id = ? FOR UPDATE");
                $stmt->execute([$secretId, $userId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    if ($existing['type'] === $type) {
                        $pdo->prepare("DELETE FROM votes WHERE secret_id = ? AND user_id = ?")
                            ->execute([$secretId, $userId]);
                    } else {
                        $pdo->prepare("UPDATE votes SET type = ? WHERE secret_id = ? AND user_id = ?")
                            ->execute([$type, $secretId, $userId]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO votes (secret_id, user_id, type) VALUES (?, ?, ?)")
                        ->execute([$secretId, $userId, $type]);
                }

                $pdo->commit();
            } catch (PDOException $txError) {
                $pdo->rollBack();
                throw $txError;
            }

            sendJson(['success' => true], 200);

        case 'get_comments':
            $secretId = isset($_GET['secret_id']) ? (int) $_GET['secret_id'] : 0;
            if ($secretId <= 0) {
                sendJson(['success' => false, 'error' => 'ID de secreto inválido.'], 400);
            }

            $stmt = $pdo->prepare(
                "SELECT c.id, c.secret_id, c.content, c.created_at 
                 FROM comments c 
                 WHERE c.secret_id = ? 
                 ORDER BY c.created_at ASC"
            );
            $stmt->execute([$secretId]);
            $rows = $stmt->fetchAll();

            $comments = array_map(static function (array $row): array {
                return [
                    'id'         => (int) $row['id'],
                    'secret_id'  => (int) $row['secret_id'],
                    'content'    => sanitizeOutput((string) $row['content']),
                    'created_at' => (string) $row['created_at'],
                ];
            }, $rows);

            sendJson(['success' => true, 'comments' => $comments], 200);

        case 'post_comment':
            if (empty($_SESSION['user_id'])) {
                sendJson(['success' => false, 'error' => 'Debes iniciar sesión para comentar.'], 401);
            }

            $secretId = isset($input['secret_id']) ? (int) $input['secret_id'] : 0;
            $content  = trim((string) ($input['content'] ?? ''));

            if ($secretId <= 0 || $content === '') {
                sendJson(['success' => false, 'error' => 'El comentario no puede estar vacío.'], 400);
            }

            if (mb_strlen($content) > 1000) {
                sendJson(['success' => false, 'error' => 'El comentario no puede superar los 1000 caracteres.'], 400);
            }

            $stmt = $pdo->prepare("INSERT INTO comments (secret_id, user_id, content) VALUES (?, ?, ?)");
            $stmt->execute([$secretId, (int) $_SESSION['user_id'], $content]);

            sendJson(['success' => true, 'comment_id' => (int) $pdo->lastInsertId()], 201);

        default:
            sendJson(['success' => false, 'error' => 'Acción no válida.'], 400);
    }
} catch (PDOException $e) {
    sendJson(['success' => false, 'error' => 'Error interno del servidor al procesar la solicitud.'], 500);
}

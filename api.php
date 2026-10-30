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
    $authorId = isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null;
    $rawUsername = !empty($row['username'])
        ? (string) $row['username']
        : ($authorId !== null ? 'usuario_' . $authorId : null);

    return [
        'id'             => (int) $row['id'],
        'user_id'        => $authorId,
        'username'       => $rawUsername !== null ? sanitizeOutput($rawUsername) : null,
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
            $status = $auth->getStatus();
            $status['unread_notifications'] = 0;
            if ($status['logged_in']) {
                try {
                    $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                    $stmtUnread->execute([(int) $status['user_id']]);
                    $status['unread_notifications'] = (int) $stmtUnread->fetchColumn();
                } catch (PDOException $e) {
                    $status['unread_notifications'] = 0;
                }
            }
            sendJson($status, 200);

        case 'register':
            $result = $auth->register($input);
            sendJson($result['payload'], $result['status']);

        case 'login':
            $result = $auth->login($input);
            sendJson($result['payload'], $result['status']);

        case 'logout':
            $result = $auth->logout();
            sendJson($result['payload'], $result['status']);

        case 'update_profile':
            if (empty($_SESSION['user_id'])) {
                sendJson(['success' => false, 'error' => 'Debes iniciar sesión para editar tu perfil.'], 401);
            }
            $result = $auth->updateProfile((int) $_SESSION['user_id'], $input);
            sendJson($result['payload'], $result['status']);

        case 'get_profile':
            $targetUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id'])
                ? (int) $_GET['user_id']
                : (isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0);

            if ($targetUserId <= 0) {
                sendJson(['success' => false, 'error' => 'Usuario no especificado.'], 400);
            }

            $stmtUser = $pdo->prepare(
                "SELECT id, username, gender, age, country, status_message, created_at FROM users WHERE id = ? LIMIT 1"
            );
            $stmtUser->execute([$targetUserId]);
            $userRow = $stmtUser->fetch();

            if (!$userRow) {
                sendJson(['success' => false, 'error' => 'El perfil solicitado no existe.'], 404);
            }

            $viewerId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
            $secretParams = [];

            $secretsQuery = "SELECT s.*, u.username,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'like') AS likes,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'dislike') AS dislikes,
                (SELECT COUNT(*) FROM comments c WHERE c.secret_id = s.id) AS comments_count";

            if ($viewerId > 0) {
                $secretsQuery .= ", (SELECT v.type FROM votes v WHERE v.secret_id = s.id AND v.user_id = ? LIMIT 1) AS user_vote";
                $secretParams[] = $viewerId;
            } else {
                $secretsQuery .= ", NULL AS user_vote";
            }

            $secretsQuery .= " FROM secrets s LEFT JOIN users u ON u.id = s.user_id WHERE s.user_id = ? ORDER BY s.created_at DESC";
            $secretParams[] = $targetUserId;

            $stmtSecrets = $pdo->prepare($secretsQuery);
            $stmtSecrets->execute($secretParams);
            $userSecrets = array_map('formatSecretRow', $stmtSecrets->fetchAll());

            // Obtener los comentarios realizados por este usuario junto con información del secreto
            $stmtComments = $pdo->prepare(
                "SELECT c.id, c.secret_id, c.parent_id, c.content, c.created_at,
                        s.title AS secret_title, s.category AS secret_category,
                        pu.username AS parent_username, pc.user_id AS parent_user_id
                 FROM comments c
                 INNER JOIN secrets s ON s.id = c.secret_id
                 LEFT JOIN comments pc ON pc.id = c.parent_id
                 LEFT JOIN users pu ON pu.id = pc.user_id
                 WHERE c.user_id = ?
                 ORDER BY c.created_at DESC"
            );
            $stmtComments->execute([$targetUserId]);
            $commentRows = $stmtComments->fetchAll();

            $userComments = array_map(static function (array $row): array {
                $parentUsername = !empty($row['parent_username'])
                    ? sanitizeOutput((string) $row['parent_username'])
                    : ($row['parent_user_id'] !== null ? 'usuario_' . (int) $row['parent_user_id'] : null);

                return [
                    'id'              => (int) $row['id'],
                    'secret_id'       => (int) $row['secret_id'],
                    'secret_title'    => $row['secret_title'] !== null && $row['secret_title'] !== '' ? sanitizeOutput((string) $row['secret_title']) : null,
                    'secret_category' => sanitizeOutput((string) $row['secret_category']),
                    'parent_id'       => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                    'parent_username' => $parentUsername,
                    'content'         => sanitizeOutput((string) $row['content']),
                    'created_at'      => (string) $row['created_at'],
                ];
            }, $commentRows);

            $username = !empty($userRow['username']) ? (string) $userRow['username'] : 'usuario_' . $userRow['id'];

            sendJson([
                'success' => true,
                'profile' => [
                    'id'             => (int) $userRow['id'],
                    'username'       => sanitizeOutput($username),
                    'gender'         => sanitizeOutput((string) ($userRow['gender'] ?? 'Otro')),
                    'age'            => $userRow['age'] !== null ? (int) $userRow['age'] : null,
                    'country'        => sanitizeOutput((string) ($userRow['country'] ?? 'Argentina')),
                    'status_message' => sanitizeOutput((string) ($userRow['status_message'] ?? '¡Hola! Estoy en TuSecreto.')),
                    'created_at'     => (string) $userRow['created_at'],
                    'is_own_profile' => $viewerId === (int) $userRow['id'],
                ],
                'secrets'  => $userSecrets,
                'comments' => $userComments,
            ], 200);

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

            // Consulta parametrizada que incluye el autor del secreto, votos y total de comentarios
            $query = "SELECT s.*, u.username,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'like') AS likes,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'dislike') AS dislikes,
                (SELECT COUNT(*) FROM comments c WHERE c.secret_id = s.id) AS comments_count";

            if ($userId > 0) {
                $query .= ", (SELECT v.type FROM votes v WHERE v.secret_id = s.id AND v.user_id = ? LIMIT 1) AS user_vote";
                $params[] = $userId;
            } else {
                $query .= ", NULL AS user_vote";
            }

            $query .= " FROM secrets s LEFT JOIN users u ON u.id = s.user_id WHERE 1=1";

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

            $query = "SELECT s.*, u.username,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'like') AS likes,
                (SELECT COUNT(*) FROM votes v WHERE v.secret_id = s.id AND v.type = 'dislike') AS dislikes,
                (SELECT COUNT(*) FROM comments c WHERE c.secret_id = s.id) AS comments_count";

            if ($userId > 0) {
                $query .= ", (SELECT v.type FROM votes v WHERE v.secret_id = s.id AND v.user_id = ? LIMIT 1) AS user_vote";
                $params[] = $userId;
            } else {
                $query .= ", NULL AS user_vote";
            }

            $query .= " FROM secrets s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1";
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

            // Obtiene comentarios con su autor real (@username) y datos del comentario padre si es respuesta
            $stmt = $pdo->prepare(
                "SELECT c.id, c.secret_id, c.user_id, c.parent_id, c.content, c.created_at,
                        u.username,
                        pc.user_id AS parent_user_id,
                        pu.username AS parent_username
                 FROM comments c
                 LEFT JOIN users u ON u.id = c.user_id
                 LEFT JOIN comments pc ON pc.id = c.parent_id
                 LEFT JOIN users pu ON pu.id = pc.user_id
                 WHERE c.secret_id = ?
                 ORDER BY c.created_at ASC"
            );
            $stmt->execute([$secretId]);
            $rows = $stmt->fetchAll();

            $comments = array_map(static function (array $row): array {
                $authorId = $row['user_id'] !== null ? (int) $row['user_id'] : null;
                $username = !empty($row['username'])
                    ? (string) $row['username']
                    : ($authorId !== null ? 'usuario_' . $authorId : 'usuario');

                $parentAuthorId = $row['parent_user_id'] !== null ? (int) $row['parent_user_id'] : null;
                $parentUsername = !empty($row['parent_username'])
                    ? sanitizeOutput((string) $row['parent_username'])
                    : ($parentAuthorId !== null ? 'usuario_' . $parentAuthorId : null);

                return [
                    'id'              => (int) $row['id'],
                    'secret_id'       => (int) $row['secret_id'],
                    'user_id'         => $authorId,
                    'username'        => sanitizeOutput($username),
                    'parent_id'       => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                    'parent_user_id'  => $parentAuthorId,
                    'parent_username' => $parentUsername,
                    'content'         => sanitizeOutput((string) $row['content']),
                    'created_at'      => (string) $row['created_at'],
                ];
            }, $rows);

            sendJson(['success' => true, 'comments' => $comments], 200);

        case 'post_comment':
            if (empty($_SESSION['user_id'])) {
                sendJson(['success' => false, 'error' => 'Debes iniciar sesión para comentar.'], 401);
            }

            $actorId  = (int) $_SESSION['user_id'];
            $secretId = isset($input['secret_id']) ? (int) $input['secret_id'] : 0;
            $parentId = isset($input['parent_id']) && $input['parent_id'] !== null && $input['parent_id'] !== ''
                ? (int) $input['parent_id']
                : null;
            $content  = trim((string) ($input['content'] ?? ''));

            if ($secretId <= 0 || $content === '') {
                sendJson(['success' => false, 'error' => 'El comentario no puede estar vacío.'], 400);
            }

            if (mb_strlen($content) > 1000) {
                sendJson(['success' => false, 'error' => 'El comentario no puede superar los 1000 caracteres.'], 400);
            }

            // Verificar que el secreto exista y obtener su autor para notificarle
            $stmtSecret = $pdo->prepare("SELECT id, user_id FROM secrets WHERE id = ? LIMIT 1");
            $stmtSecret->execute([$secretId]);
            $secretRow = $stmtSecret->fetch();

            if (!$secretRow) {
                sendJson(['success' => false, 'error' => 'El secreto no existe.'], 404);
            }

            $secretOwnerId = $secretRow['user_id'] !== null ? (int) $secretRow['user_id'] : 0;
            $parentOwnerId = 0;

            // Si es respuesta a otro comentario, validar que pertenezca al mismo secreto
            if ($parentId !== null && $parentId > 0) {
                $stmtParent = $pdo->prepare("SELECT id, user_id, secret_id FROM comments WHERE id = ? LIMIT 1");
                $stmtParent->execute([$parentId]);
                $parentRow = $stmtParent->fetch();

                if (!$parentRow || (int) $parentRow['secret_id'] !== $secretId) {
                    sendJson(['success' => false, 'error' => 'El comentario al que intentas responder no es válido.'], 400);
                }
                $parentOwnerId = $parentRow['user_id'] !== null ? (int) $parentRow['user_id'] : 0;
            } else {
                $parentId = null;
            }

            // Transacción para insertar comentario y generar notificaciones correspondientes
            $pdo->beginTransaction();
            try {
                $stmtInsert = $pdo->prepare(
                    "INSERT INTO comments (secret_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)"
                );
                $stmtInsert->execute([$secretId, $actorId, $parentId, $content]);
                $commentId = (int) $pdo->lastInsertId();

                // 1. Notificar al autor del comentario padre si alguien respondió a su comentario
                if ($parentId !== null && $parentOwnerId > 0 && $parentOwnerId !== $actorId) {
                    $stmtNotifReply = $pdo->prepare(
                        "INSERT INTO notifications (user_id, actor_id, secret_id, comment_id, type) VALUES (?, ?, ?, ?, 'reply_comment')"
                    );
                    $stmtNotifReply->execute([$parentOwnerId, $actorId, $secretId, $commentId]);
                }

                // 2. Notificar al autor del secreto si alguien comentó su secreto (evitando duplicar si ya recibió reply_comment)
                if ($secretOwnerId > 0 && $secretOwnerId !== $actorId && $secretOwnerId !== $parentOwnerId) {
                    $stmtNotifSecret = $pdo->prepare(
                        "INSERT INTO notifications (user_id, actor_id, secret_id, comment_id, type) VALUES (?, ?, ?, ?, 'comment_secret')"
                    );
                    $stmtNotifSecret->execute([$secretOwnerId, $actorId, $secretId, $commentId]);
                }

                $pdo->commit();
            } catch (PDOException $txError) {
                $pdo->rollBack();
                throw $txError;
            }

            sendJson(['success' => true, 'comment_id' => $commentId], 201);

        case 'get_notifications':
            if (empty($_SESSION['user_id'])) {
                sendJson(['success' => false, 'error' => 'No autenticado.'], 401);
            }

            $userId = (int) $_SESSION['user_id'];

            $stmtUnread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmtUnread->execute([$userId]);
            $unreadCount = (int) $stmtUnread->fetchColumn();

            $stmtNotifs = $pdo->prepare(
                "SELECT n.id, n.secret_id, n.comment_id, n.type, n.is_read, n.created_at,
                        n.actor_id, u.username AS actor_username,
                        c.content AS comment_content
                 FROM notifications n
                 INNER JOIN users u ON u.id = n.actor_id
                 INNER JOIN comments c ON c.id = n.comment_id
                 WHERE n.user_id = ?
                 ORDER BY n.created_at DESC
                 LIMIT 30"
            );
            $stmtNotifs->execute([$userId]);
            $rows = $stmtNotifs->fetchAll();

            $notifications = array_map(static function (array $row): array {
                $actorId = (int) $row['actor_id'];
                $actorUsername = !empty($row['actor_username'])
                    ? (string) $row['actor_username']
                    : 'usuario_' . $actorId;
                $snippet = mb_substr(trim((string) $row['comment_content']), 0, 90);
                if (mb_strlen(trim((string) $row['comment_content'])) > 90) {
                    $snippet .= '...';
                }

                return [
                    'id'              => (int) $row['id'],
                    'secret_id'       => (int) $row['secret_id'],
                    'comment_id'      => (int) $row['comment_id'],
                    'type'            => (string) $row['type'],
                    'is_read'         => (bool) $row['is_read'],
                    'actor_id'        => $actorId,
                    'actor_username'  => sanitizeOutput($actorUsername),
                    'comment_snippet' => sanitizeOutput($snippet),
                    'created_at'      => (string) $row['created_at'],
                ];
            }, $rows);

            sendJson([
                'success'       => true,
                'unread_count'  => $unreadCount,
                'notifications' => $notifications,
            ], 200);

        case 'mark_notifications_read':
            if (empty($_SESSION['user_id'])) {
                sendJson(['success' => false, 'error' => 'No autenticado.'], 401);
            }

            $userId = (int) $_SESSION['user_id'];
            $notifId = isset($input['notification_id']) ? (int) $input['notification_id'] : 0;

            if ($notifId > 0) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
                $stmt->execute([$notifId, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
                $stmt->execute([$userId]);
            }

            sendJson(['success' => true], 200);

        default:
            sendJson(['success' => false, 'error' => 'Acción no válida.'], 400);
    }
} catch (PDOException $e) {
    sendJson(['success' => false, 'error' => 'Error interno del servidor al procesar la solicitud.'], 500);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/db_connect.php';

/**
 * Clase de Autenticación y Gestión de Usuarios.
 * Implementa validaciones estrictas, hashing seguro, gestión de perfil y protección contra fijación de sesión.
 */
class Auth
{
    private PDO $pdo;

    private const ALLOWED_GENDERS = ['Mujer', 'Hombre', 'Otro'];
    private const ALLOWED_COUNTRIES = ['Argentina', 'México', 'Colombia', 'España', 'Perú', 'Chile', 'Uruguay', 'Otro'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 86400 * 7,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * Devuelve el estado actual de la sesión junto con los datos de perfil del usuario.
     */
    public function getStatus(): array
    {
        if (empty($_SESSION['user_id'])) {
            return [
                'logged_in' => false,
                'user'      => null,
            ];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, username, email, gender, age, country, status_message, created_at FROM users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->logout();
            return [
                'logged_in' => false,
                'user'      => null,
            ];
        }

        $username = !empty($user['username']) ? (string) $user['username'] : 'usuario_' . $user['id'];

        return [
            'logged_in' => true,
            'user_id'   => (int) $user['id'],
            'user'      => [
                'id'             => (int) $user['id'],
                'username'       => htmlspecialchars($username, ENT_QUOTES, 'UTF-8'),
                'email'          => htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8'),
                'gender'         => htmlspecialchars((string) ($user['gender'] ?? 'Otro'), ENT_QUOTES, 'UTF-8'),
                'age'            => $user['age'] !== null ? (int) $user['age'] : null,
                'country'        => htmlspecialchars((string) ($user['country'] ?? 'Argentina'), ENT_QUOTES, 'UTF-8'),
                'status_message' => htmlspecialchars((string) ($user['status_message'] ?? '¡Hola! Estoy en TuSecreto.'), ENT_QUOTES, 'UTF-8'),
                'created_at'     => (string) $user['created_at'],
            ],
        ];
    }

    /**
     * Actualiza el estado y los datos básicos del perfil del usuario autenticado.
     */
    public function updateProfile(int $userId, array $input): array
    {
        $statusMessage = trim((string) ($input['status_message'] ?? ''));
        $gender        = trim((string) ($input['gender'] ?? 'Otro'));
        $age           = isset($input['age']) && $input['age'] !== '' ? (int) $input['age'] : null;
        $country       = trim((string) ($input['country'] ?? 'Argentina'));

        if (mb_strlen($statusMessage) > 160) {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'El estado no puede superar los 160 caracteres.'],
            ];
        }

        if ($statusMessage === '') {
            $statusMessage = '¡Hola! Estoy en TuSecreto.';
        }

        if (!in_array($gender, self::ALLOWED_GENDERS, true)) {
            $gender = 'Otro';
        }

        if (!in_array($country, self::ALLOWED_COUNTRIES, true)) {
            $country = 'Argentina';
        }

        if ($age !== null && ($age < 13 || $age > 99)) {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'La edad debe estar entre 13 y 99 años.'],
            ];
        }

        $stmt = $this->pdo->prepare(
            "UPDATE users SET status_message = ?, gender = ?, age = ?, country = ? WHERE id = ?"
        );
        $stmt->execute([$statusMessage, $gender, $age, $country, $userId]);

        return [
            'status'  => 200,
            'payload' => [
                'success' => true,
                'message' => 'Perfil actualizado correctamente.',
                'user'    => $this->getStatus()['user'],
            ],
        ];
    }

    /**
     * Registra un nuevo usuario validando alias, email, fortaleza de contraseña y datos demográficos.
     */
    public function register(array $input): array
    {
        $username        = trim((string) ($input['username'] ?? ''));
        $email           = strtolower(trim((string) ($input['email'] ?? '')));
        $password        = (string) ($input['password'] ?? '');
        $passwordConfirm = (string) ($input['password_confirm'] ?? '');
        $gender          = trim((string) ($input['gender'] ?? 'Otro'));
        $age             = isset($input['age']) && $input['age'] !== '' ? (int) $input['age'] : null;
        $country         = trim((string) ($input['country'] ?? 'Argentina'));

        // Validación de alias (3 a 25 caracteres alfanuméricos o guiones bajos)
        if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,25}$/', $username)) {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'El alias debe tener entre 3 y 25 caracteres (solo letras, números y guion bajo).'],
            ];
        }

        // Validación de correo electrónico
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'Ingresa un correo electrónico válido.'],
            ];
        }

        // Validación de fortaleza de contraseña (mínimo 8 caracteres, al menos 1 letra y 1 número)
        if (mb_strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres e incluir letras y números.'],
            ];
        }

        if ($passwordConfirm !== '' && !hash_equals($password, $passwordConfirm)) {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'Las contraseñas no coinciden.'],
            ];
        }

        if (!in_array($gender, self::ALLOWED_GENDERS, true)) {
            $gender = 'Otro';
        }

        if (!in_array($country, self::ALLOWED_COUNTRIES, true)) {
            $country = 'Argentina';
        }

        if ($age !== null && ($age < 13 || $age > 99)) {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'La edad debe estar entre 13 y 99 años.'],
            ];
        }

        // Verificar duplicados de email o username con sentencia preparada
        $stmtCheck = $this->pdo->prepare("SELECT email, username FROM users WHERE email = ? OR username = ? LIMIT 1");
        $stmtCheck->execute([$email, $username]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            if (strcasecmp((string) $existing['email'], $email) === 0) {
                return [
                    'status'  => 409,
                    'payload' => ['success' => false, 'error' => 'Este correo electrónico ya está registrado.'],
                ];
            }
            return [
                'status'  => 409,
                'payload' => ['success' => false, 'error' => 'Este alias ya está en uso, elige otro.'],
            ];
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $stmtInsert = $this->pdo->prepare(
            "INSERT INTO users (username, email, password, gender, age, country, status_message) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmtInsert->execute([$username, $email, $passwordHash, $gender, $age, $country, '¡Hola! Estoy en TuSecreto.']);

        $userId = (int) $this->pdo->lastInsertId();

        // Regenerar el ID de sesión tras autenticarse para prevenir Session Fixation
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;

        return [
            'status'  => 201,
            'payload' => [
                'success' => true,
                'message' => 'Cuenta creada exitosamente.',
                'user'    => $this->getStatus()['user'],
            ],
        ];
    }

    /**
     * Inicia sesión permitiendo ingresar con correo electrónico o alias (username).
     */
    public function login(array $input): array
    {
        $identifier = trim((string) ($input['email'] ?? $input['identifier'] ?? ''));
        $password   = (string) ($input['password'] ?? '');

        if ($identifier === '' || $password === '') {
            return [
                'status'  => 400,
                'payload' => ['success' => false, 'error' => 'Completa tu correo/alias y contraseña.'],
            ];
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, password FROM users WHERE email = ? OR username = ? LIMIT 1"
        );
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password'])) {
            return [
                'status'  => 401,
                'payload' => ['success' => false, 'error' => 'Credenciales incorrectas.'],
            ];
        }

        // Rehash transparente si el algoritmo por defecto de PHP se actualizó
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $this->pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, (int) $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        return [
            'status'  => 200,
            'payload' => [
                'success' => true,
                'message' => 'Sesión iniciada correctamente.',
                'user'    => $this->getStatus()['user'],
            ],
        ];
    }

    /**
     * Cierra la sesión actual de forma segura.
     */
    public function logout(): array
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();

        return [
            'status'  => 200,
            'payload' => ['success' => true],
        ];
    }
}

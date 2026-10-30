<?php
declare(strict_types=1);

/**
 * Módulo de conexión PDO a MySQL.
 * Configura prepared statements nativos y manejo estricto de errores.
 */
class Database
{
    private const HOST = 'localhost';
    private const DB_NAME = 'clonts';
    private const USER = 'root';
    private const PASS = '';

    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', self::HOST, self::DB_NAME);
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            self::$instance = new PDO($dsn, self::USER, self::PASS, $options);
            self::ensureSchemaUpgrades(self::$instance);
        }

        return self::$instance;
    }

    /**
     * Asegura que las tablas tengan las columnas e índices necesarios para perfiles,
     * estados, respuestas en comentarios y notificaciones sin perder datos previos.
     */
    private static function ensureSchemaUpgrades(PDO $pdo): void
    {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('username', $cols, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(50) UNIQUE NULL AFTER id");
            }
            if (!in_array('gender', $cols, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN gender VARCHAR(20) DEFAULT 'Otro' AFTER password");
            }
            if (!in_array('age', $cols, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN age INT NULL AFTER gender");
            }
            if (!in_array('country', $cols, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN country VARCHAR(50) DEFAULT 'Argentina' AFTER age");
            }
            if (!in_array('status_message', $cols, true)) {
                $pdo->exec("ALTER TABLE users ADD COLUMN status_message VARCHAR(160) DEFAULT '¡Hola! Estoy en TuSecreto.' AFTER country");
            }

            // Asignar alias automático a cuentas antiguas que no tengan username
            $pdo->exec("UPDATE users SET username = CONCAT('usuario_', id) WHERE username IS NULL OR username = ''");
        } catch (PDOException $e) {
            // Ignorar si la tabla users aún no existe
        }

        try {
            // Asegurar columna parent_id en comments para respuestas anidadas
            $commentCols = $pdo->query("SHOW COLUMNS FROM comments")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('parent_id', $commentCols, true)) {
                $pdo->exec("ALTER TABLE comments ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER user_id");
                $pdo->exec("ALTER TABLE comments ADD INDEX idx_comments_parent (parent_id)");
            }
        } catch (PDOException $e) {
            // Ignorar si la tabla comments aún no existe
        }

        try {
            // Crear tabla de notificaciones compatible tanto con motores MyISAM como InnoDB en WAMP
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    actor_id INT NOT NULL,
                    secret_id INT NOT NULL,
                    comment_id INT NOT NULL,
                    type ENUM('comment_secret', 'reply_comment') NOT NULL,
                    is_read TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_notifications_user_read (user_id, is_read),
                    INDEX idx_notifications_created_at (created_at)
                ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            // Ignorar si ocurre un error de permisos
        }
    }
}

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
     * Asegura que la tabla users tenga las columnas extendidas del nuevo sistema de registro
     * sin perder datos existentes en instalaciones previas.
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
        } catch (PDOException $e) {
            // Si la tabla aún no existe, se ignora para permitir su creación manual o vía script SQL.
        }
    }
}

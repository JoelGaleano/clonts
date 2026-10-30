CREATE DATABASE IF NOT EXISTS clonts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE clonts;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    gender VARCHAR(20) DEFAULT 'Otro',
    age INT NULL,
    country VARCHAR(50) DEFAULT 'Argentina',
    status_message VARCHAR(160) DEFAULT '¡Hola! Estoy en TuSecreto.',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS secrets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    title VARCHAR(150) NULL,
    content TEXT NOT NULL,
    gender VARCHAR(20) NOT NULL,
    age INT NULL,
    country VARCHAR(50) DEFAULT 'Desconocido',
    category VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_secrets_user (user_id),
    INDEX idx_secrets_category (category),
    INDEX idx_secrets_country (country),
    INDEX idx_secrets_gender_age (gender, age),
    INDEX idx_secrets_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    secret_id INT NOT NULL,
    user_id INT NULL,
    parent_id INT NULL DEFAULT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_comments_secret (secret_id),
    INDEX idx_comments_user (user_id),
    INDEX idx_comments_parent (parent_id),
    FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS votes (
    secret_id INT NOT NULL,
    user_id INT NOT NULL,
    type ENUM('like', 'dislike') NOT NULL,
    PRIMARY KEY (secret_id, user_id),
    FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    INDEX idx_notifications_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE,
    FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

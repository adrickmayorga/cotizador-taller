-- Ejecutar UNA VEZ sobre la misma base srnu_cotizador del ERP existente.
-- Respalda primero la base. No elimina ni modifica cotizaciones.
CREATE TABLE IF NOT EXISTS mobile_api_tokens (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 INDEX idx_mobile_token_user (user_id),
 INDEX idx_mobile_token_expiry (expires_at),
 CONSTRAINT fk_mobile_token_user FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mobile_login_attempts (
 ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 last_attempt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Mantenimiento periódico opcional:
-- DELETE FROM mobile_api_tokens WHERE expires_at < NOW();
-- DELETE FROM mobile_login_attempts WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 1 DAY);

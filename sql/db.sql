-- Создание базы данных (если не существует)
CREATE DATABASE IF NOT EXISTS gate_controller CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gate_controller;

-- Таблица для хранения номеров телефонов и групп доступа
CREATE TABLE numbers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL UNIQUE,
    group_id TINYINT NOT NULL DEFAULT 0 COMMENT '0 - без задержки, 1 - с задержкой 5 мин',
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_phone (phone_number),
    INDEX idx_group (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Связь номера с адресом и номером участка
-- Один адрес/участок может быть привязан к нескольким номерам
CREATE TABLE number_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    number_id INT NOT NULL,
    address VARCHAR(255) NULL,
    plot_number VARCHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_number_id (number_id),
    INDEX idx_address (address),
    INDEX idx_plot_number (plot_number),
    CONSTRAINT fk_number_locations_number
        FOREIGN KEY (number_id) REFERENCES numbers(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица для логов событий
CREATE TABLE logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(50) NOT NULL,
    phone_number VARCHAR(20),
    group_id TINYINT,
    status VARCHAR(50),
    details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица для команд SMS, отправленных через веб-интерфейс
CREATE TABLE sms_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    phone_number VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица для хранения конфигурации шлюза (токен, хеш данных и т.п.)
CREATE TABLE gateway_config (
    config_key VARCHAR(50) PRIMARY KEY,
    config_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Пользователи админ-панели
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Вставляем начальные значения
INSERT INTO gateway_config (config_key, config_value) VALUES 
('api_token', MD5(CONCAT(RAND(), NOW()))),  -- случайный токен, нужно будет заменить на свой
('numbers_hash', '');

-- Лог удалённых номеров (дельта-синхронизация шлюза)
CREATE TABLE IF NOT EXISTS numbers_deleted_phones (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  phone_number VARCHAR(20) NOT NULL,
  deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Таблица для отслеживания версии данных (используется для синхронизации)
-- Хеш будет обновляться триггерами
DELIMITER $$

CREATE TRIGGER update_numbers_hash_insert AFTER INSERT ON numbers
FOR EACH ROW
BEGIN
    SET SESSION group_concat_max_len = 16777216;
    UPDATE gateway_config 
    SET config_value = (
        SELECT MD5(GROUP_CONCAT(CONCAT(phone_number, '|', group_id) ORDER BY phone_number SEPARATOR ','))
        FROM numbers WHERE active = 1
    ) WHERE config_key = 'numbers_hash';
END$$

CREATE TRIGGER update_numbers_hash_update AFTER UPDATE ON numbers
FOR EACH ROW
BEGIN
    SET SESSION group_concat_max_len = 16777216;
    UPDATE gateway_config 
    SET config_value = (
        SELECT MD5(GROUP_CONCAT(CONCAT(phone_number, '|', group_id) ORDER BY phone_number SEPARATOR ','))
        FROM numbers WHERE active = 1
    ) WHERE config_key = 'numbers_hash';
END$$

CREATE TRIGGER update_numbers_hash_delete AFTER DELETE ON numbers
FOR EACH ROW
BEGIN
    SET SESSION group_concat_max_len = 16777216;
    INSERT INTO numbers_deleted_phones (phone_number) VALUES (OLD.phone_number);
    UPDATE gateway_config 
    SET config_value = (
        SELECT MD5(GROUP_CONCAT(CONCAT(phone_number, '|', group_id) ORDER BY phone_number SEPARATOR ','))
        FROM numbers WHERE active = 1
    ) WHERE config_key = 'numbers_hash';
END$$

DELIMITER ;
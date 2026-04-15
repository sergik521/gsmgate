-- Пересоздание триггеров numbers_hash: явный CONCAT(phone,'|',group) внутри GROUP_CONCAT.
-- SET SESSION group_concat_max_len: иначе GROUP_CONCAT усечён до ~1024 байт — MD5 не меняется
-- при правках «далеко» в списке номеров (триггер считает хеш по укороченной строке).
-- Старая форма GROUP_CONCAT(phone_number, group_id, ...) на части версий MySQL/MariaDB
-- могла не менять результат при изменении только group_id.
-- Выполнить на существующей БД после бэкапа.
-- Если таблицы numbers_deleted_phones ещё нет — сначала sql/migration_numbers_delta.sql

USE gate_controller;

CREATE TABLE IF NOT EXISTS numbers_deleted_phones (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  phone_number VARCHAR(20) NOT NULL,
  deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS update_numbers_hash_insert;
DROP TRIGGER IF EXISTS update_numbers_hash_update;
DROP TRIGGER IF EXISTS update_numbers_hash_delete;

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

-- Пересчитать кэш хеша целиком (старый мог быть по усечённому GROUP_CONCAT)
SET SESSION group_concat_max_len = 16777216;
UPDATE gateway_config
SET config_value = (
    SELECT MD5(GROUP_CONCAT(CONCAT(phone_number, '|', group_id) ORDER BY phone_number SEPARATOR ','))
    FROM numbers WHERE active = 1
)
WHERE config_key = 'numbers_hash';

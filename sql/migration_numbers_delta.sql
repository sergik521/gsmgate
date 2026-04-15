-- Лог удалённых номеров для дельта-синхронизации.
-- MySQL: на одну таблицу допускается один триггер AFTER DELETE — лог объединён с update_numbers_hash_delete.
-- Выполнить один раз на существующей БД: mysql ... < migration_numbers_delta.sql

USE gate_controller;

CREATE TABLE IF NOT EXISTS numbers_deleted_phones (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  phone_number VARCHAR(20) NOT NULL,
  deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TRIGGER IF EXISTS numbers_after_delete_log;

DROP TRIGGER IF EXISTS update_numbers_hash_delete;

DELIMITER $$
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

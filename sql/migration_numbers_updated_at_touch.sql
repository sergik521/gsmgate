-- Гарантирует updated_at при смене group_id / phone / active (дельта get_data завязана на updated_at).
-- Выполнить при необходимости: mysql ... < migration_numbers_updated_at_touch.sql
-- На чистой схеме db.sql колонка уже с ON UPDATE CURRENT_TIMESTAMP — триггер дублирует защиту на старых БД.

USE gate_controller;

DROP TRIGGER IF EXISTS numbers_touch_updated_at_before_upd;

DELIMITER $$
CREATE TRIGGER numbers_touch_updated_at_before_upd BEFORE UPDATE ON numbers
FOR EACH ROW
BEGIN
  IF NEW.group_id <> OLD.group_id
     OR NEW.phone_number <> OLD.phone_number
     OR NEW.active <> OLD.active THEN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
  END IF;
END$$
DELIMITER ;

CREATE TABLE IF NOT EXISTS `address` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `address` VARCHAR(191) NOT NULL DEFAULT '',
    `plot_number` VARCHAR(64) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_address_plot` (`address`, `plot_number`),
    KEY `idx_address` (`address`),
    KEY `idx_plot_number` (`plot_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;


ALTER TABLE `numbers`
    ADD COLUMN `address_id` INT NULL AFTER `active`,
    ADD INDEX `idx_numbers_address_id` (`address_id`),
    ADD CONSTRAINT `fk_numbers_address`
        FOREIGN KEY (`address_id`) REFERENCES `address`(`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE;
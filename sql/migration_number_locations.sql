-- Миграция: связка номеров с адресами и участками
USE gate_controller;

CREATE TABLE IF NOT EXISTS number_locations (
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

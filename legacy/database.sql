-- database.sql
CREATE DATABASE IF NOT EXISTS doorlock_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE doorlock_db;

-- Table: persons (users/card holders)
CREATE TABLE IF NOT EXISTS persons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    card_number VARCHAR(50) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table: doors
CREATE TABLE IF NOT EXISTS doors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table: permissions (which persons can open which doors)
CREATE TABLE IF NOT EXISTS permissions (
    person_id INT NOT NULL,
    door_id INT NOT NULL,
    PRIMARY KEY (person_id, door_id),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (door_id) REFERENCES doors(id) ON DELETE CASCADE
);

-- Table: access_logs
CREATE TABLE IF NOT EXISTS access_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT,
    door_id INT,
    status ENUM('granted','denied') NOT NULL,
    access_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE SET NULL,
    FOREIGN KEY (door_id) REFERENCES doors(id) ON DELETE SET NULL
);

-- Optional: users for web login (separate from card holders)
CREATE TABLE IF NOT EXISTS web_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin (password: 1234) - you should hash it!
INSERT INTO web_users (username, password_hash) VALUES ('admin', '$2y$10$YourHashHere') 
ON DUPLICATE KEY UPDATE username=username;
-- For quick testing, we'll handle dummy auth in auth.php, but you can replace with DB later.

-- Sample data for testing
INSERT INTO persons (name, card_number) VALUES 
('علی احمدی', '1234567890'),
('مریم کریمی', '0987654321')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO doors (name) VALUES 
('درب ورودی اصلی'),
('درب طبقه اول'),
('درب سرور')
ON DUPLICATE KEY UPDATE name=name;

INSERT INTO permissions (person_id, door_id) VALUES 
(1,1), (1,2), (2,1);

INSERT INTO access_logs (person_id, door_id, status) VALUES 
(1,1,'granted'),
(2,1,'granted'),
(1,2,'denied');
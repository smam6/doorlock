<?php
/**
 * includes/config.php
 * Database connection + site-wide constants.
 * Edit the values below to match your XAMPP / MySQL setup.
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'doorlock_clean');

// Arduino door controller connection details
define('ARDUINO_IP', '192.168.1.100');   // change to your Arduino's IP
define('ARDUINO_PORT', 80);              // default HTTP port

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}
$conn->set_charset('utf8mb4');

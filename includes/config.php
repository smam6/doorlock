<?php
// Database configuration
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'doorlock_db';

$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}
// Set charset to UTF-8
$conn->set_charset('utf8mb4');
// in includes/config.php, after database connection

define('ARDUINO_IP', '192.168.1.100');  // change to your Arduino's IP
define('ARDUINO_PORT', 80);              // default HTTP port
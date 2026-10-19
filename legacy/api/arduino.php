<?php
// api/arduino.php - Proxy to Arduino door controller
session_start();
header('Content-Type: application/json');

// Authentication check
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Arduino IP address (change to your Arduino's IP)
$arduino_ip = '192.168.1.100'; // <<< CONFIGURE THIS

// Get the endpoint from query string (e.g., ?endpoint=status)
$endpoint = isset($_GET['endpoint']) ? preg_replace('/[^a-zA-Z0-9_\/]/', '', $_GET['endpoint']) : '';
if (!$endpoint) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit;
}

// Build full URL
$url = "http://$arduino_ip/$endpoint";

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 seconds timeout
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

// Forward method and body
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE') {
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $input = file_get_contents('php://input');
    if ($input) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
    }
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Forward the response from Arduino
http_response_code($httpCode);
echo $response;
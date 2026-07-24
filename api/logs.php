<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
require_once '../includes/config.php';
require_once '../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Retrieve logs with optional limit
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $stmt = $conn->prepare("
        SELECT l.id, l.access_time, l.status, 
               p.name as person_name, d.name as door_name
        FROM access_logs l
        LEFT JOIN persons p ON l.person_id = p.id
        LEFT JOIN doors d ON l.door_id = d.id
        ORDER BY l.access_time DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $logs = $result->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['logs' => $logs]);
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
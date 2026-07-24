<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
require_once '../includes/config.php';

// Simple stub: return all permissions for a person or all
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    $person_id = $_GET['person_id'] ?? 0;
    if ($person_id) {
        $stmt = $conn->prepare("SELECT door_id FROM permissions WHERE person_id = ?");
        $stmt->bind_param("i", $person_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $doors = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['permissions' => array_column($doors, 'door_id')]);
    } else {
        // return all permissions
        $result = $conn->query("SELECT * FROM permissions");
        $perms = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['permissions' => $perms]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
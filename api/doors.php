<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
require_once '../includes/config.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $stmt = $conn->prepare("SELECT id, name, location, is_active FROM doors ORDER BY id");
        $stmt->execute();
        $result = $stmt->get_result();
        $doors = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['doors' => $doors]);
        break;

    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $location = $input['location'] ?? '';
        $is_active = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'Name required']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO doors (name, location, is_active) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $name, $location, $is_active);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
        }
        $stmt->close();
        break;

    case 'PUT':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            exit;
        }
        // Toggle is_active
        $stmt = $conn->prepare("UPDATE doors SET is_active = NOT is_active WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
        }
        $stmt->close();
        break;

    case 'DELETE':
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? 0;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            exit;
        }
        $stmt = $conn->prepare("DELETE FROM doors WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error']);
        }
        $stmt->close();
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
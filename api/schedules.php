<?php
// api/schedules.php - Manage access schedules
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
        // Retrieve all schedules with person and door names
        $stmt = $conn->prepare("
            SELECT s.id, s.person_id, s.door_id, s.days, s.start_time, s.end_time, s.enabled,
                   p.name as person_name, d.name as door_name
            FROM schedules s
            LEFT JOIN persons p ON s.person_id = p.id
            LEFT JOIN doors d ON s.door_id = d.id
            ORDER BY s.id DESC
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        $schedules = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['schedules' => $schedules]);
        break;

    case 'POST':
        // Add new schedule
        $input = json_decode(file_get_contents('php://input'), true);
        $person_id = $input['person_id'] ?? 0;
        $door_id = $input['door_id'] ?? 0;
        $days = $input['days'] ?? ''; // comma-separated day numbers (1-7)
        $start_time = $input['start_time'] ?? '';
        $end_time = $input['end_time'] ?? '';
        $enabled = isset($input['enabled']) ? (int)$input['enabled'] : 1;

        if (!$person_id || !$door_id || !$start_time || !$end_time) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            exit;
        }

        $stmt = $conn->prepare("INSERT INTO schedules (person_id, door_id, days, start_time, end_time, enabled) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssi", $person_id, $door_id, $days, $start_time, $end_time, $enabled);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $conn->error]);
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
        $stmt = $conn->prepare("DELETE FROM schedules WHERE id = ?");
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
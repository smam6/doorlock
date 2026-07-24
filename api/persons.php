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
        // Retrieve all persons with new fields
        $stmt = $conn->prepare("SELECT id, name, card_number, role, enabled FROM persons ORDER BY id");
        $stmt->execute();
        $result = $stmt->get_result();
        $dbPersons = $result->fetch_all(MYSQLI_ASSOC);
        
        // *** CORRECTION: Map DB fields to Frontend expected fields ***
        $persons = array_map(function($user) {
            return [
                'id' => $user['id'],
                'name' => $user['name'],          
                'card_number' => $user['card_number'],    
                'role' => $user['role'],
                'enabled' => $user['enabled']
            ];
        }, $dbPersons);
        
        echo json_encode(['persons' => $persons]); // Ensure the root key matches what frontend expects (e.g., 'persons')
        break;

    case 'POST':
        // Add new person
        $input = json_decode(file_get_contents('php://input'), true);
        $name = $input['name'] ?? '';
        $card = $input['card_number'] ?? '';
        $role = $input['role'] ?? 'user'; // default to user
        $enabled = isset($input['enabled']) ? (int)$input['enabled'] : 1; // default enabled

        if (!$name || !$card) {
            http_response_code(400);
            echo json_encode(['error' => 'Name and card number required']);
            exit;
        }

        // Check for duplicate card number
        $check = $conn->prepare("SELECT id FROM persons WHERE card_number = ?");
        $check->bind_param("s", $card);
        $check->execute();
        $check->store_result();
        if ($check->num_rows > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Card number already exists']);
            exit;
        }
        $check->close();

        $stmt = $conn->prepare("INSERT INTO persons (name, card_number, role, enabled) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $name, $card, $role, $enabled);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $conn->error]);
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
            $stmt = $conn->prepare("UPDATE persons SET enabele = 1  WHERE id = ?");
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
        $stmt = $conn->prepare("DELETE FROM persons WHERE id = ?");
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
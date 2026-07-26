<?php
// api/schedules.php — مدیریت برنامه زمانی شخصی و گروهی
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

    /* ─────────────────────────────────────────────────────────
       GET  ?type=personal  [&person_id=X] [&door_id=Y]
       GET  ?type=group     [&group_id=X]  [&door_id=Y]
       type پیش‌فرض: personal
    ───────────────────────────────────────────────────────── */
    case 'GET':
        $type      = $_GET['type']      ?? 'personal';
        $person_id = isset($_GET['person_id']) ? (int)$_GET['person_id'] : null;
        $group_id  = isset($_GET['group_id'])  ? (int)$_GET['group_id']  : null;
        $door_id   = isset($_GET['door_id'])   ? (int)$_GET['door_id']   : null;

        if ($type === 'group') {
            $where  = "1=1";
            $params = [];
            $types  = "";
            if ($group_id) { $where .= " AND gs.group_id = ?"; $params[] = $group_id; $types .= "i"; }
            if ($door_id)  { $where .= " AND gs.door_id  = ?"; $params[] = $door_id;  $types .= "i"; }

            $stmt = $conn->prepare(
                "SELECT gs.id, gs.group_id, gs.door_id,
                        gs.days, gs.start_time, gs.end_time, gs.enabled,
                        g.name AS group_name, d.name AS door_name
                 FROM group_schedules gs
                 LEFT JOIN `groups` g ON g.id = gs.group_id
                 LEFT JOIN doors    d ON d.id = gs.door_id
                 WHERE $where
                 ORDER BY gs.id DESC"
            );
            if ($types) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode(['type' => 'group', 'schedules' => $rows]);

        } else {
            $where  = "1=1";
            $params = [];
            $types  = "";
            if ($person_id) { $where .= " AND s.person_id = ?"; $params[] = $person_id; $types .= "i"; }
            if ($door_id){ $where .= " AND s.door_id   = ?"; $params[] = $door_id;   $types .= "i"; }

            $stmt = $conn->prepare(
                "SELECT s.id, s.person_id, s.door_id,
                        s.days, s.start_time, s.end_time, s.enabled,
                        p.name AS person_name, d.name AS door_name
                 FROM schedules s
                 LEFT JOIN persons p ON p.id = s.person_id
                 LEFT JOIN doors   d ON d.id = s.door_id
                 WHERE $where
                 ORDER BY s.id DESC"
            );
            if ($types) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            echo json_encode(['type' => 'personal', 'schedules' => $rows]);
        }
        break;

    /* ─────────────────────────────────────────────────────────
       POST  personal: { type:"personal", person_id, door_id, days, start_time, end_time, enabled }
       POST  group:{ type:"group",    group_id,door_id, days, start_time, end_time, enabled }
    ───────────────────────────────────────────────────────── */
    case 'POST':
        $input      = json_decode(file_get_contents('php://input'), true);
        $type       = $input['type']       ?? 'personal';
        $door_id    = (int)($input['door_id']    ?? 0);
        $days       = trim($input['days']       ?? '');
        $start_time = trim($input['start_time'] ?? '');
        $end_time   = trim($input['end_time']   ?? '');
        $enabled    = isset($input['enabled']) ? (int)$input['enabled'] : 1;

        if (!$door_id || !$start_time || !$end_time) {
            http_response_code(400);
            echo json_encode(['error' => 'door_id, start_time, end_time required']);
            exit;
        }

        if ($type === 'group') {
            $group_id = (int)($input['group_id'] ?? 0);
            if (!$group_id) {
                http_response_code(400);
                echo json_encode(['error' => 'group_id required']);
                exit;
            }
            $stmt = $conn->prepare(
                "INSERT INTO group_schedules (group_id, door_id, days, start_time, end_time, enabled)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
                        $stmt->bind_param("iisssi", $group_id, $door_id, $days, $start_time, $end_time, $enabled);
        } else {
            $person_id = (int)($input['person_id'] ?? 0);
            if (!$person_id) {
                http_response_code(400);
                echo json_encode(['error' => 'person_id required']);
                exit;
            }
            $stmt = $conn->prepare(
                "INSERT INTO schedules (person_id, door_id, days, start_time, end_time, enabled)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("iisssi", $person_id, $door_id, $days, $start_time, $end_time, $enabled);
        }

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'id' => $stmt->insert_id]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
        break;

    /* ─────────────────────────────────────────────────────────
       PUT  body: { id, type, days, start_time, end_time, enabled }
    ───────────────────────────────────────────────────────── */
    case 'PUT':
        $input      = json_decode(file_get_contents('php://input'), true);
        $type       = $input['type']       ?? 'personal';
        $id         = (int)($input['id']         ?? 0);
        $days       = trim($input['days']       ?? '');
        $start_time = trim($input['start_time'] ?? '');
        $end_time   = trim($input['end_time']   ?? '');
        $enabled    = isset($input['enabled']) ? (int)$input['enabled'] : 1;

        if (!$id || !$start_time || !$end_time) {
            http_response_code(400);
            echo json_encode(['error' => 'id, start_time, end_time required']);
            exit;
        }

        $table = ($type === 'group') ? 'group_schedules' : 'schedules';

        $stmt = $conn->prepare(
            "UPDATE `$table`
             SET days = ?, start_time = ?, end_time = ?, enabled = ?
             WHERE id = ?"
        );
        $stmt->bind_param("sssii", $days, $start_time, $end_time, $enabled, $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Record not found']);
            }
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
        break;

    /* ─────────────────────────────────────────────────────────
       DELETE  body: { id, type }
    ───────────────────────────────────────────────────────── */
    case 'DELETE':
        $input = json_decode(file_get_contents('php://input'), true);
        $type  = $input['type'] ?? 'personal';
        $id    = (int)($input['id'] ?? 0);

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'id required']);
            exit;
        }

        $table = ($type === 'group') ? 'group_schedules' : 'schedules';

        $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Record not found']);
            }
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

$conn->close();

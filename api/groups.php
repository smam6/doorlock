<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    // ─── GET ───────────────────────────────────────────────
    case 'GET':
        // GET api/groups.php?id=X  →  جزئیات یک گروه + اعضا + درب‌ها
        if (isset($_GET['id'])) {
            $id = (int)$_GET['id'];

            $group = dbQuerySingle(
                "SELECT id, name, description, created_at FROM `groups` WHERE id = ?",
                [$id]
            );
            if (!$group) {
                http_response_code(404);
                echo json_encode(['error' => 'Group not found']);
                exit;
            }

            $members = dbQuery(
                "SELECT p.id, p.name, p.card_number
                 FROM persons p
                 INNER JOIN person_groups pg ON pg.person_id = p.id
                 WHERE pg.group_id = ?",
                [$id]
            );

            $doors = dbQuery(
                "SELECT d.id, d.name, d.location
                 FROM doors d
                 INNER JOIN group_door_permissions gdp ON gdp.door_id = d.id
                 WHERE gdp.group_id = ?",
                [$id]
            );

            echo json_encode([
                'group'   => $group,
                'members' => $members ?: [],
                'doors'   => $doors   ?: [],
            ]);

        } else {
            // GET api/groups.php  →  لیست همه گروه‌ها با تعداد اعضا
            $groups = dbQuery(
                "SELECT g.id, g.name, g.description, g.created_at,
                        COUNT(DISTINCT pg.person_id) AS member_count,
                        COUNT(DISTINCT gdp.door_id)  AS door_count
                 FROM `groups` g
                 LEFT JOIN person_groups pg          ON pg.group_id  = g.id
                 LEFT JOIN group_door_permissions gdp ON gdp.group_id = g.id
                 GROUP BY g.id
                 ORDER BY g.name"
            );
            echo json_encode(['groups' => $groups ?: []]);
        }
        break;

    // ─── POST ──────────────────────────────────────────────
    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true);
        $action = $body['action'] ?? 'create';

        if ($action === 'create') {
            // ایجاد گروه جدید
            $name = trim($body['name'] ?? '');
            $desc = trim($body['description'] ?? '');

            if ($name === '') {
                http_response_code(400);
                echo json_encode(['error' => 'نام گروه الزامی است']);
                exit;
            }

            $id = dbInsert(
                "INSERT INTO `groups` (name, description) VALUES (?, ?)",
                [$name, $desc ?: null]
            );

            echo json_encode(['success' => true, 'id' => $id]);

        } elseif ($action === 'add_member') {
            // افزودن عضو به گروه
            $groupId  = (int)($body['group_id']  ?? 0);
            $personId = (int)($body['person_id'] ?? 0);

            if (!$groupId || !$personId) {
                http_response_code(400);
                echo json_encode(['error' => 'group_id و person_id الزامی است']);
                exit;
            }

            dbQuery(
                "INSERT IGNORE INTO person_groups (person_id, group_id) VALUES (?, ?)",
                [$personId, $groupId]
            );
            echo json_encode(['success' => true]);

        } elseif ($action === 'add_door') {
            // افزودن دسترسی درب به گروه
            $groupId = (int)($body['group_id'] ?? 0);
            $doorId  = (int)($body['door_id']  ?? 0);

            if (!$groupId || !$doorId) {
                http_response_code(400);
                echo json_encode(['error' => 'group_id و door_id الزامی است']);
                exit;
            }

            dbQuery(
                "INSERT IGNORE INTO group_door_permissions (group_id, door_id) VALUES (?, ?)",
                [$groupId, $doorId]
            );
            echo json_encode(['success' => true]);
        }
        break;

    // ─── DELETE ────────────────────────────────────────────
    case 'DELETE':
        $body   = json_decode(file_get_contents('php://input'), true);
        $action = $body['action'] ?? 'delete_group';

        if ($action === 'delete_group') {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id الزامی است']); exit; }

            dbQuery("DELETE FROM `groups` WHERE id = ?", [$id]);
            echo json_encode(['success' => true]);

        } elseif ($action === 'remove_member') {
            $groupId  = (int)($body['group_id']  ?? 0);
            $personId = (int)($body['person_id'] ?? 0);

            dbQuery(
                "DELETE FROM person_groups WHERE person_id = ? AND group_id = ?",
                [$personId, $groupId]
            );
            echo json_encode(['success' => true]);

        } elseif ($action === 'remove_door') {
            $groupId = (int)($body['group_id'] ?? 0);
            $doorId  = (int)($body['door_id']  ?? 0);

            dbQuery(
                "DELETE FROM group_door_permissions WHERE group_id = ? AND door_id = ?",
                [$groupId, $doorId]
            );
            echo json_encode(['success' => true]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}

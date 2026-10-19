<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiHelpers;
use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Util;

Api::run(function (Request $request): array {
    Api::guard($request, 'doors.read');

    $localStart = Util::localNow()->setTime(0, 0);
    $utcStart = $localStart->setTimezone(new \DateTimeZone('UTC'));
    $utcEnd = $localStart->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));

    $stats = [
        'persons' => (int) Db::scalar('SELECT COUNT(*) FROM persons WHERE archived_at IS NULL'),
        'persons_enabled' => (int) Db::scalar('SELECT COUNT(*) FROM persons WHERE archived_at IS NULL AND enabled = 1'),
        'cards' => (int) Db::scalar("SELECT COUNT(*) FROM cards WHERE status = 'active'"),
        'doors' => (int) Db::scalar('SELECT COUNT(*) FROM doors WHERE archived_at IS NULL AND is_active = 1'),
        'devices' => (int) Db::scalar('SELECT COUNT(*) FROM devices WHERE is_active = 1'),
        'groups' => (int) Db::scalar('SELECT COUNT(*) FROM person_groups WHERE archived_at IS NULL AND enabled = 1'),
        'grants_active' => (int) Db::scalar(
            'SELECT COUNT(*) FROM temporary_grants
              WHERE revoked_at IS NULL
                AND valid_from <= ?
                AND valid_to > ?
                AND (max_uses IS NULL OR used_count < max_uses)',
            [Util::toDb(Util::utcNow()), Util::toDb(Util::utcNow())]
        ),
    ];

    $today = [
        'granted' => 0,
        'denied' => 0,
        'error' => 0,
    ];
    foreach (Db::all(
        'SELECT decision, COUNT(*) AS total FROM access_logs WHERE occurred_at >= ? AND occurred_at < ? GROUP BY decision',
        [Util::toDb($utcStart), Util::toDb($utcEnd)]
    ) as $row) {
        $today[(string) $row['decision']] = (int) $row['total'];
    }

    $recent = array_map(
        static fn (array $row) => ApiHelpers::accessLog($row),
        Db::all(
            'SELECT l.*, p.name AS person_name, d.name AS door_name, dv.name AS device_name
               FROM access_logs l
               LEFT JOIN persons p ON p.id = l.person_id
               LEFT JOIN doors d ON d.id = l.door_id
               LEFT JOIN devices dv ON dv.id = l.device_id
              ORDER BY l.occurred_at DESC, l.id DESC LIMIT 10'
        )
    );

    return ['data' => [
        'stats' => $stats,
        'today' => $today,
        'recent_access' => $recent,
        'server_time' => Util::iso(Util::toDb(Util::utcNow())),
    ]];
});

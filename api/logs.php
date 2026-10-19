<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiError;
use DoorLock\ApiHelpers;
use DoorLock\Config;
use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Response;
use DoorLock\Rbac;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    $user = Api::guard($request, 'logs.read');

    if ($request->method() !== 'GET') {
        throw ApiError::methodNotAllowed();
    }

    [$where, $params] = logsFilter($request);
    $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
    $from = ' FROM access_logs l
              LEFT JOIN persons p ON p.id = l.person_id
              LEFT JOIN doors d ON d.id = l.door_id
              LEFT JOIN devices dv ON dv.id = l.device_id';

    if (($request->query('format') ?? '') === 'csv') {
        Rbac::require($user, 'logs.export');
        $limit = min(Config::int('limits.max_export_rows', 10000), 10000);
        $rows = Db::all(
            'SELECT l.*, p.name AS person_name, d.name AS door_name, dv.name AS device_name'
            . $from . $sqlWhere . ' ORDER BY l.occurred_at DESC, l.id DESC LIMIT ' . $limit,
            $params
        );
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new ApiError('export_failed', 'Unable to prepare the export.', 500);
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'id', 'occurred_at_utc', 'decision', 'reason', 'person', 'door', 'device',
            'card_last4', 'source', 'unlock_seconds', 'request_id',
        ]);
        foreach ($rows as $row) {
            $serialised = ApiHelpers::accessLog($row);
            fputcsv($stream, array_map(
                static fn ($cell) => ApiHelpers::csvCell($cell),
                [
                    $serialised['id'],
                    $serialised['occurred_at'],
                    $serialised['decision'],
                    $serialised['reason'],
                    $serialised['person_name'],
                    $serialised['door_name'],
                    $serialised['device_name'],
                    $serialised['card_last4'],
                    $serialised['source'],
                    $serialised['unlock_seconds'],
                    $serialised['request_id'],
                ]
            ));
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return [
            '__raw' => is_string($csv) ? $csv : '',
            'content_type' => 'text/csv; charset=utf-8',
            'status' => 200,
            'headers' => [
                'Content-Disposition' => 'attachment; filename="access-log-' . gmdate('Ymd-His') . '.csv"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ];
    }

    [$page, $perPage] = Validator::pagination($request);
    $total = (int) Db::scalar('SELECT COUNT(*)' . $from . $sqlWhere, $params);
    $offset = ($page - 1) * $perPage;
    $rows = Db::all(
        'SELECT l.*, p.name AS person_name, d.name AS door_name, dv.name AS device_name'
        . $from . $sqlWhere . ' ORDER BY l.occurred_at DESC, l.id DESC LIMIT ? OFFSET ?',
        array_merge($params, [$perPage, $offset])
    );
    return [
        'data' => ['logs' => array_map(static fn (array $row) => ApiHelpers::accessLog($row), $rows)],
        'meta' => ApiHelpers::pageMeta($page, $perPage, $total),
    ];
});

/** @return array{0:array<int,string>,1:array<int,mixed>} */
function logsFilter(Request $request): array
{
    $where = [];
    $params = [];
    $from = Validator::filterBoundary($request->query('from'), 'from');
    if ($from !== null) {
        $where[] = 'l.occurred_at >= ?';
        $params[] = Util::toDb($from);
    }
    $toInput = $request->query('to');
    $to = Validator::filterBoundary($toInput, 'to', true);
    if ($to !== null) {
        // A date-only upper bound is exclusive at the start of the next day.
        $where[] = 'l.occurred_at < ?';
        $params[] = Util::toDb($to);
    }
    foreach (['door_id' => 'l.door_id', 'person_id' => 'l.person_id', 'device_id' => 'l.device_id'] as $param => $column) {
        $value = $request->query($param);
        if ($value !== null && $value !== '') {
            $where[] = $column . ' = ?';
            $params[] = Validator::id($value, $param);
        }
    }
    $decision = $request->query('decision');
    if ($decision !== null && $decision !== '') {
        $where[] = 'l.decision = ?';
        $params[] = Validator::enum($decision, 'decision', ['granted', 'denied', 'error']);
    }
    $reason = $request->query('reason');
    if ($reason !== null && $reason !== '') {
        if (!preg_match('/^[a-z_]{3,40}$/', $reason)) {
            throw ApiError::validation('reason', 'Invalid reason filter.');
        }
        $where[] = 'l.reason = ?';
        $params[] = $reason;
    }
    $requestId = $request->query('request_id');
    if ($requestId !== null && $requestId !== '') {
        $where[] = 'l.request_id = ?';
        $params[] = Validator::requestId($requestId);
    }
    return [$where, $params];
}

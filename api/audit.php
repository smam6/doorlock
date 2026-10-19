<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiError;
use DoorLock\ApiHelpers;
use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    Api::guard($request, 'audit.read');

    if ($request->method() !== 'GET') {
        throw ApiError::methodNotAllowed();
    }

    [$page, $perPage] = Validator::pagination($request);
    $where = [];
    $params = [];
    if ($request->query('from') !== null) {
        $from = Validator::filterBoundary($request->query('from'), 'from');
        if ($from !== null) {
            $where[] = 'created_at >= ?';
            $params[] = Util::toDb($from);
        }
    }
    if ($request->query('to') !== null) {
        $to = Validator::filterBoundary($request->query('to'), 'to', true);
        if ($to !== null) {
            $where[] = 'created_at < ?';
            $params[] = Util::toDb($to);
        }
    }
    if ($request->query('actor_user_id') !== null && $request->query('actor_user_id') !== '') {
        $where[] = 'actor_user_id = ?';
        $params[] = Validator::id($request->query('actor_user_id'), 'actor_user_id');
    }
    foreach (['action' => 64, 'entity_type' => 32, 'entity_id' => 64] as $field => $maxLength) {
        $value = $request->query($field);
        if ($value !== null && $value !== '') {
            $where[] = $field . ' = ?';
            $params[] = Validator::string($value, $field, 1, $maxLength);
        }
    }
    $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int) Db::scalar('SELECT COUNT(*) FROM audit_logs' . $sqlWhere, $params);
    $offset = ($page - 1) * $perPage;
    $rows = Db::all(
        'SELECT * FROM audit_logs' . $sqlWhere . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
        array_merge($params, [$perPage, $offset])
    );
    return [
        'data' => ['entries' => array_map(static fn (array $row) => ApiHelpers::audit($row), $rows)],
        'meta' => ApiHelpers::pageMeta($page, $perPage, $total),
    ];
});

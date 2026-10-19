<?php

declare(strict_types=1);

define('DOORLOCK_API_REQUEST', true);
require dirname(__DIR__) . '/includes/bootstrap.php';

use DoorLock\Api;
use DoorLock\ApiError;
use DoorLock\ApiHelpers;
use DoorLock\Audit;
use DoorLock\ConstraintViolation;
use DoorLock\Db;
use DoorLock\Request;
use DoorLock\Util;
use DoorLock\Validator;

Api::run(function (Request $request): array {
    Api::guard($request, 'cards.read', 'cards.write');

    if ($request->method() === 'GET') {
        [$page, $perPage] = Validator::pagination($request);
        $where = [];
        $params = [];
        $personFilter = $request->query('person_id');
        if ($personFilter !== null && $personFilter !== '') {
            $personId = Validator::id($personFilter, 'person_id');
            ApiHelpers::activePerson($personId, true);
            $where[] = 'c.person_id = ?';
            $params[] = $personId;
        }
        $status = $request->query('status');
        if ($status !== null && $status !== '') {
            $where[] = 'c.status = ?';
            $params[] = Validator::enum($status, 'status', ['active', 'revoked', 'lost']);
        }
        $sqlWhere = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Db::scalar('SELECT COUNT(*) FROM cards c' . $sqlWhere, $params);
        $offset = ($page - 1) * $perPage;
        $rows = Db::all(
            'SELECT c.*, p.name AS person_name FROM cards c JOIN persons p ON p.id = c.person_id'
            . $sqlWhere . ' ORDER BY c.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, $offset])
        );
        $cards = array_map(static function (array $row): array {
            $card = ApiHelpers::card($row);
            $card['person_name'] = (string) $row['person_name'];
            return $card;
        }, $rows);
        return ['data' => ['cards' => $cards], 'meta' => ApiHelpers::pageMeta($page, $perPage, $total)];
    }

    if ($request->method() === 'POST') {
        $input = $request->json();
        $personId = Validator::id($input['person_id'] ?? null, 'person_id');
        $person = ApiHelpers::activePerson($personId);
        $cardNumber = Validator::cardNumber($input['card_number'] ?? null);
        $status = isset($input['status']) ? Validator::enum($input['status'], 'status', ['active', 'revoked', 'lost']) : 'active';
        $count = (int) Db::scalar('SELECT COUNT(*) FROM cards WHERE person_id = ?', [$personId]);
        if ($count >= 10) {
            throw new ApiError('limit_reached', 'A person cannot have more than 10 cards.', 409);
        }
        $now = Util::toDb(Util::utcNow());
        try {
            $cardId = Db::tx(function () use ($personId, $cardNumber, $status, $now, $person): int {
                $cardId = Db::insert(
                    'INSERT INTO cards (person_id, card_number, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$personId, $cardNumber, $status, $now, $now]
                );
                Audit::record('cards.create', 'card', (string) $cardId, null, [
                    'person_id' => $personId,
                    'person_name' => $person['name'],
                    'card_number' => $cardNumber,
                    'status' => $status,
                ]);
                return $cardId;
            });
        } catch (ConstraintViolation $e) {
            if ($e->kind === 'unique') {
                throw new ApiError('conflict', 'This card number is already assigned.', 409, ['card_number' => 'Already in use.']);
            }
            throw $e;
        }
        $card = Db::one('SELECT * FROM cards WHERE id = ?', [$cardId]);
        return ['status' => 201, 'data' => ['card' => ApiHelpers::card($card ?? [])]];
    }

    if ($request->method() === 'PUT') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $card = Db::one('SELECT * FROM cards WHERE id = ?', [$id]);
        if ($card === null) {
            throw ApiError::notFound('Card not found.');
        }
        $newStatus = Validator::enum($input['status'] ?? null, 'status', ['active', 'revoked', 'lost']);
        if ($newStatus === (string) $card['status']) {
            return ['data' => ['card' => ApiHelpers::card($card)]];
        }
        Db::tx(function () use ($id, $newStatus, $card): void {
            Db::exec('UPDATE cards SET status = ?, updated_at = ? WHERE id = ?', [
                $newStatus,
                Util::toDb(Util::utcNow()),
                $id,
            ]);
            Audit::record('cards.update', 'card', (string) $id, ['status' => (string) $card['status']], ['status' => $newStatus]);
        });
        $updated = Db::one('SELECT * FROM cards WHERE id = ?', [$id]);
        return ['data' => ['card' => ApiHelpers::card($updated ?? [])]];
    }

    if ($request->method() === 'DELETE') {
        $input = $request->json();
        $id = Validator::id($input['id'] ?? null, 'id');
        $card = Db::one('SELECT * FROM cards WHERE id = ?', [$id]);
        if ($card === null) {
            throw ApiError::notFound('Card not found.');
        }
        Db::tx(function () use ($id, $card): void {
            Db::exec('DELETE FROM cards WHERE id = ?', [$id]);
            Audit::record('cards.delete', 'card', (string) $id, [
                'person_id' => (int) $card['person_id'],
                'card_number' => (string) $card['card_number'],
                'status' => (string) $card['status'],
            ]);
        });
        return ['data' => ['deleted' => true]];
    }

    throw ApiError::methodNotAllowed();
});

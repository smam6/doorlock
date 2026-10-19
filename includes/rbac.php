<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Role-based access control.
 *
 * Roles:
 *   viewer   - read-only access to access-control data
 *   operator - day-to-day access administration (people, cards, groups,
 *              schedules, permissions, temporary grants)
 *   admin    - everything, including doors, devices, web users and audit logs
 *
 * The permission names are deliberately coarse; the matrix is the single
 * source of truth and is enforced server-side for every request.
 */
final class Rbac
{
    public const ROLES = ['admin', 'operator', 'viewer'];

    private const MATRIX = [
        'admin' => [
            'persons.read', 'persons.write',
            'cards.read', 'cards.write',
            'groups.read', 'groups.write',
            'schedules.read', 'schedules.write',
            'permissions.read', 'permissions.write',
            'grants.read', 'grants.write',
            'doors.read', 'doors.write',
            'devices.read', 'devices.write',
            'logs.read', 'logs.export',
            'audit.read',
            'users.read', 'users.write',
        ],
        'operator' => [
            'persons.read', 'persons.write',
            'cards.read', 'cards.write',
            'groups.read', 'groups.write',
            'schedules.read', 'schedules.write',
            'permissions.read', 'permissions.write',
            'grants.read', 'grants.write',
            'doors.read',
            'devices.read',
            'logs.read', 'logs.export',
        ],
        'viewer' => [
            'persons.read',
            'cards.read',
            'groups.read',
            'schedules.read',
            'permissions.read',
            'grants.read',
            'doors.read',
            'devices.read',
            'logs.read',
        ],
    ];

    public static function can(string $role, string $action): bool
    {
        if (!isset(self::MATRIX[$role])) {
            return false;
        }
        return in_array($action, self::MATRIX[$role], true);
    }

    /** @param array<string,mixed> $user */
    public static function require(array $user, string $action): void
    {
        $role = (string) ($user['role'] ?? '');
        if (!self::can($role, $action)) {
            Logger::security('authorization_denied', [
                'user_id' => $user['id'] ?? null,
                'role' => $role,
                'action' => $action,
            ]);
            throw ApiError::forbidden();
        }
    }

    /** @return array<int,string> */
    public static function permissionsFor(string $role): array
    {
        return self::MATRIX[$role] ?? [];
    }
}

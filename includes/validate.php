<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Input validation. Every method either returns a normalised value or throws
 * an ApiError with a stable field-level message.
 */
final class Validator
{
    private const COMMON_PASSWORDS = [
        '123456789012', 'password1234', 'qwertyuiop12', 'adminadmin12',
        'letmein12345', 'welcome12345', 'changeme1234', 'doorlock1234',
        'password', '12345678', '123456789', 'qwerty123', 'admin123',
    ];

    public static function string(mixed $value, string $field, int $min = 1, int $max = 100, bool $trim = true): string
    {
        if (!is_string($value)) {
            throw ApiError::validation($field, 'This field must be a string.');
        }
        $value = $trim ? trim($value) : $value;
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ApiError::validation($field, 'This field must be valid UTF-8.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw ApiError::validation($field, 'This field contains unsupported control characters.');
        }
        $length = mb_strlen($value);
        if ($length < $min) {
            throw ApiError::validation($field, "This field must contain at least {$min} characters.");
        }
        if ($length > $max) {
            throw ApiError::validation($field, "This field must contain at most {$max} characters.");
        }
        return $value;
    }

    public static function nullableString(mixed $value, string $field, int $max = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::string($value, $field, 0, $max);
    }

    public static function intValue(mixed $value, string $field, int $min = 0, int $max = PHP_INT_MAX): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            $int = (int) $value;
        } else {
            throw ApiError::validation($field, 'This field must be an integer.');
        }
        if ($int < $min || $int > $max) {
            throw ApiError::validation($field, "This field must be between {$min} and {$max}.");
        }
        return $int;
    }

    public static function id(mixed $value, string $field = 'id'): int
    {
        return self::intValue($value, $field, 1, 2147483647);
    }

    public static function boolValue(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        throw ApiError::validation($field, 'This field must be a boolean.');
    }

    /** @param array<int,string> $allowed */
    public static function enum(mixed $value, string $field, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw ApiError::validation($field, 'Unsupported value. Allowed: ' . implode(', ', $allowed) . '.');
        }
        return $value;
    }

    public static function cardNumber(mixed $value, string $field = 'card_number'): string
    {
        if (!is_string($value)) {
            throw ApiError::validation($field, 'Card number must be a string.');
        }
        $normalized = Util::normalizeCardNumber($value);
        if ($normalized === null) {
            throw ApiError::validation($field, 'Card number must contain 4-64 letters, digits, "_" or "-".');
        }
        return $normalized;
    }

    public static function username(mixed $value): string
    {
        if (!is_string($value)) {
            throw ApiError::validation('username', 'Username must be a string.');
        }
        $normalized = strtolower(trim($value));
        if (preg_match(Util::USERNAME_PATTERN, $normalized) !== 1) {
            throw ApiError::validation('username', 'Username must be 3-64 characters: letters, digits, dot, underscore or hyphen.');
        }
        return $normalized;
    }

    public static function role(mixed $value): string
    {
        return self::enum($value, 'role', Rbac::ROLES);
    }

    public static function password(mixed $value, string $username = ''): string
    {
        if (!is_string($value)) {
            throw ApiError::validation('password', 'Password must be a string.');
        }
        $min = max(8, Config::int('security.min_password_length', 12));
        $length = strlen($value);
        if ($length < $min) {
            throw ApiError::validation('password', "Password must contain at least {$min} characters.");
        }
        if ($length > 200) {
            throw ApiError::validation('password', 'Password must contain at most 200 characters.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw ApiError::validation('password', 'Password contains unsupported control characters.');
        }
        $lower = strtolower($value);
        if (in_array($lower, self::COMMON_PASSWORDS, true)) {
            throw ApiError::validation('password', 'This password is too common.');
        }
        if ($username !== '' && str_contains($lower, strtolower($username))) {
            throw ApiError::validation('password', 'Password must not contain the username.');
        }
        if (preg_match('/^(.)\1+$/', $value) === 1) {
            throw ApiError::validation('password', 'Password must not consist of one repeated character.');
        }
        return $value;
    }

    /** Accepts "HH:MM" or "HH:MM:SS"; returns canonical "HH:MM:SS". */
    public static function time(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw ApiError::validation($field, 'Time must be a string in HH:MM format.');
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m) !== 1) {
            throw ApiError::validation($field, 'Time must be a valid 24-hour time (HH:MM).');
        }
        $seconds = $m[3] ?? '00';
        return sprintf('%s:%s:%s', $m[1], $m[2], $seconds);
    }

    /**
     * Accepts "1,3,5" or an array of day numbers. 1 = Saturday ... 7 = Friday.
     * Returns the canonical, de-duplicated, sorted CSV string.
     */
    public static function days(mixed $value): string
    {
        $parts = [];
        if (is_string($value)) {
            $parts = Util::csvToArray($value);
        } elseif (is_array($value)) {
            if (!array_is_list($value)) {
                throw ApiError::validation('days', 'Days must be a list.');
            }
            foreach ($value as $item) {
                if (!is_int($item) && !is_string($item)) {
                    throw ApiError::validation('days', 'Days must contain numbers 1-7.');
                }
                $parts[] = (string) $item;
            }
        } else {
            throw ApiError::validation('days', 'Days must be a comma-separated list or an array.');
        }
        $days = [];
        foreach ($parts as $part) {
            if (preg_match('/^[1-7]$/', $part) !== 1) {
                throw ApiError::validation('days', 'Each day must be a number from 1 (Saturday) to 7 (Friday).');
            }
            $days[(int) $part] = true;
        }
        if ($days === []) {
            throw ApiError::validation('days', 'Select at least one day.');
        }
        $numbers = array_keys($days);
        sort($numbers, SORT_NUMERIC);
        return implode(',', $numbers);
    }

    /** Parse an ISO-8601 timestamp with offset, or a naive local datetime. */
    public static function dateTime(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            throw ApiError::validation($field, 'Timestamp is required.');
        }
        $raw = trim($value);
        $appTz = Util::appTimezone();
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw) === 1) {
            $raw = str_replace('T', ' ', $raw);
            $format = strlen($raw) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $raw, $appTz);
            if (!$dt instanceof \DateTimeImmutable) {
                throw ApiError::validation($field, 'Invalid timestamp.');
            }
            return $dt->setTimezone(new \DateTimeZone('UTC'));
        }
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            throw ApiError::validation($field, 'Timestamp must be ISO-8601 (for example 2026-05-01T12:00:00+02:00).');
        }
        if (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $raw) !== 1) {
            throw ApiError::validation($field, 'Timestamp must include a timezone offset.');
        }
        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    /** Date or datetime used by list filters. */
    public static function filterBoundary(mixed $value, string $field, bool $endOfDay = false): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw ApiError::validation($field, 'Timestamp filter must be a string.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, Util::appTimezone());
            if (!$dt instanceof \DateTimeImmutable) {
                throw ApiError::validation($field, 'Invalid date.');
            }
            return $endOfDay ? $dt->modify('+1 day')->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC')) : $dt->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        }
        return self::dateTime($value, $field);
    }

    public static function deviceUid(mixed $value): string
    {
        if (!is_string($value) || preg_match(Util::DEVICE_UID_PATTERN, $value) !== 1) {
            throw new ApiError('invalid_device', 'Device authentication failed.', 401);
        }
        return $value;
    }

    public static function requestId(mixed $value): string
    {
        if (!is_string($value) || !Util::isValidRequestId($value)) {
            throw new ApiError('invalid_request_id', 'X-Request-Id must match [A-Za-z0-9._-]{8,64}.', 400);
        }
        return $value;
    }

    /** @return array{0:int,1:int} page, per_page */
    public static function pagination(Request $request): array
    {
        $page = $request->queryInt('page', 1, 1, 1000000);
        $perPage = $request->queryInt(
            'per_page',
            Config::int('limits.default_page_size', 50),
            1,
            Config::int('limits.max_page_size', 200)
        );
        return [$page, $perPage];
    }
}

<?php

declare(strict_types=1);

namespace DoorLock;

/** Time, identifier and input normalisation helpers shared by the API and tests. */
final class Util
{
    /** True for native PHP CLI and for the PHP.wasm CLI adapter. */
    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' || getenv('DOORLOCK_CLI') === '1';
    }

    public const CARD_PATTERN = '/^[0-9A-Za-z_-]{4,64}$/';
    public const REQUEST_ID_PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';
    public const DEVICE_UID_PATTERN = '/^[a-f0-9]{32}$/';
    public const NONCE_PATTERN = '/^[a-f0-9]{16,64}$/';
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,64}$/';

    public static function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function localNow(): \DateTimeImmutable
    {
        return self::utcNow()->setTimezone(self::appTimezone());
    }

    public static function appTimezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) Config::get('app_timezone', 'UTC'));
    }

    /** Database representation: UTC, millisecond precision, sortable text. */
    public static function toDb(\DateTimeInterface $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    public static function fromDb(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        $value = rtrim($value, 'Z');
        foreach (['Y-m-d H:i:s.v', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }
        throw new \InvalidArgumentException('Unparseable database timestamp.');
    }

    public static function iso(?string $dbValue): ?string
    {
        $dt = self::fromDb($dbValue);
        return $dt?->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Local wall-clock day number used by schedules: 1 = Saturday ... 7 = Friday. */
    public static function weekdayNumber(\DateTimeImmutable $local): int
    {
        return match ($local->format('N')) {
            '1' => 3, // Monday
            '2' => 4, // Tuesday
            '3' => 5, // Wednesday
            '4' => 6, // Thursday
            '5' => 7, // Friday
            '6' => 1, // Saturday
            '7' => 2, // Sunday
        };
    }

    /**
     * Normalise an RFID card identifier.
     * Accepts separators commonly used by readers ("12:34:56:78", "12-34-56-78")
     * and returns a canonical upper-case token. Returns null when invalid.
     */
    public static function normalizeCardNumber(string $raw): ?string
    {
        $value = trim($raw);
        $value = preg_replace('/[\s:\-]+/', '', $value) ?? '';
        $value = strtoupper($value);
        if (preg_match(self::CARD_PATTERN, $value) !== 1) {
            return null;
        }
        return $value;
    }

    public static function maskCard(?string $card): ?string
    {
        if ($card === null || $card === '') {
            return null;
        }
        $length = strlen($card);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }
        return str_repeat('*', max(0, $length - 4)) . substr($card, -4);
    }

    public static function randomHex(int $bytes): string
    {
        if ($bytes < 1) {
            throw new \InvalidArgumentException('Byte count must be positive.');
        }
        return bin2hex(random_bytes($bytes));
    }

    public static function isValidRequestId(?string $value): bool
    {
        return is_string($value) && preg_match(self::REQUEST_ID_PATTERN, $value) === 1;
    }

    public static function jsonEncode(mixed $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to encode JSON response.', 0, $e);
        }
    }

    public static function jsonDecodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiError('invalid_json', 'Request body must be valid JSON.', 400);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new ApiError('invalid_json', 'Request body must be a JSON object.', 400);
        }
        return $decoded;
    }

    public static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max);
    }

    public static function clampInt(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /** @return array<int,string> */
    public static function csvToArray(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts, static fn ($part) => $part !== ''));
    }

    public static function nowForTest(?string $iso = null): \DateTimeImmutable
    {
        if ($iso === null || $iso === '') {
            return self::utcNow();
        }
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $iso);
        if (!$parsed instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('Invalid ISO-8601 timestamp: ' . $iso);
        }
        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }
}

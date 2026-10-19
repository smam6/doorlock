<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * HMAC request authentication for ESP8266 controllers.
 *
 * Canonical string (newline separated, no trailing newline):
 *
 *   DOORLOCK1
 *   POST
 *   check-access
 *   <device_uid>         16 random bytes, lower-case hex
 *   <unix_timestamp>     decimal seconds, UTC
 *   <nonce>              16-64 lower-case hex characters, unique per request
 *   <request_id>         8-64 characters [A-Za-z0-9._-]
 *   <sha256_hex(body)>
 *
 * Signature: hex(HMAC-SHA256(device_key, canonical_string))
 *
 * Device keys are derived from the server's app_key and the device's uid and
 * key_version. They are never stored in the database and can be rotated by
 * incrementing key_version; old keys stop working immediately.
 */
final class DeviceAuth
{
    public const HEADER_DEVICE = 'x-device-id';
    public const HEADER_TIMESTAMP = 'x-timestamp';
    public const HEADER_NONCE = 'x-nonce';
    public const HEADER_SIGNATURE = 'x-signature';
    public const HEADER_REQUEST_ID = 'x-request-id';

    public static function deriveDeviceKey(string $deviceUid, int $keyVersion): string
    {
        $material = "doorlock-device-key-v1\n{$deviceUid}\n{$keyVersion}";
        return hash_hmac('sha256', $material, Config::appKeyRaw());
    }

    public static function canonicalString(
        string $deviceUid,
        int $timestamp,
        string $nonce,
        string $requestId,
        string $body,
        string $method = 'POST',
        string $action = 'check-access'
    ): string {
        return implode("\n", [
            'DOORLOCK1',
            strtoupper($method),
            $action,
            $deviceUid,
            (string) $timestamp,
            strtolower($nonce),
            $requestId,
            hash('sha256', $body),
        ]);
    }

    public static function signature(
        string $deviceKey,
        string $deviceUid,
        int $timestamp,
        string $nonce,
        string $requestId,
        string $body,
        string $method = 'POST',
        string $action = 'check-access'
    ): string {
        return hash_hmac(
            'sha256',
            self::canonicalString($deviceUid, $timestamp, $nonce, $requestId, $body, $method, $action),
            $deviceKey
        );
    }

    /**
     * Validate the request signature, freshness and replay protection.
     *
     * @return array<string,mixed> the authenticated device row
     */
    public static function authenticate(Request $request, string $body): array
    {
        if (Config::bool('security.require_https_for_devices')
            && !Config::bool('security.allow_insecure_device_http')
            && !$request->isHttps()) {
            throw new ApiError('https_required', 'Device requests require HTTPS.', 403);
        }

        $deviceUid = $request->header(self::HEADER_DEVICE);
        $timestampRaw = $request->header(self::HEADER_TIMESTAMP);
        $nonce = $request->header(self::HEADER_NONCE);
        $signature = $request->header(self::HEADER_SIGNATURE);
        $requestId = $request->header(self::HEADER_REQUEST_ID);

        if ($deviceUid === null || $timestampRaw === null || $nonce === null || $signature === null || $requestId === null) {
            Logger::security('device_auth_missing_headers', ['path' => $request->path()]);
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }
        if (preg_match(Util::DEVICE_UID_PATTERN, $deviceUid) !== 1) {
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }
        if (preg_match('/^\d{10,11}$/', $timestampRaw) !== 1) {
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }
        $timestamp = (int) $timestampRaw;
        $skew = Config::int('security.device_clock_skew', 120);
        if (abs(time() - $timestamp) > $skew) {
            Logger::security('device_timestamp_out_of_range', ['device_uid' => $deviceUid, 'timestamp' => $timestamp]);
            throw new ApiError('timestamp_out_of_range', 'Request timestamp is outside the allowed window.', 401);
        }
        $nonce = strtolower($nonce);
        if (preg_match(Util::NONCE_PATTERN, $nonce) !== 1) {
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }
        $requestId = Validator::requestId($requestId);
        $signature = strtolower($signature);
        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }

        $device = Db::one('SELECT * FROM devices WHERE unique_id = ?', [$deviceUid]);
        if ($device === null) {
            Logger::security('device_auth_unknown_device', ['device_uid' => $deviceUid, 'ip' => $request->ip()]);
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }
        if ((int) $device['is_active'] !== 1) {
            Logger::security('device_auth_disabled', ['device_id' => (int) $device['id']]);
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }

        $expected = self::signature(
            self::deriveDeviceKey($deviceUid, (int) $device['key_version']),
            $deviceUid,
            $timestamp,
            $nonce,
            $requestId,
            $body
        );
        if (!hash_equals($expected, $signature)) {
            Logger::security('device_auth_bad_signature', ['device_id' => (int) $device['id']]);
            throw new ApiError('device_auth_failed', 'Device authentication failed.', 401);
        }

        $windowStart = Util::toDb(Util::utcNow()->modify('-60 seconds'));
        $recent = (int) Db::scalar(
            'SELECT COUNT(*) FROM device_nonces WHERE device_id = ? AND seen_at >= ?',
            [(int) $device['id'], $windowStart]
        );
        $limit = Config::int('security.device_rate_limit_per_minute', 60);
        if ($recent >= $limit) {
            Logger::security('device_rate_limited', ['device_id' => (int) $device['id']]);
            throw new ApiError('rate_limited', 'Too many requests from this device.', 429, [], ['Retry-After' => '60']);
        }

        try {
            Db::exec(
                'INSERT INTO device_nonces (device_id, nonce, seen_at) VALUES (?, ?, ?)',
                [(int) $device['id'], $nonce, Util::toDb(Util::utcNow())]
            );
        } catch (ConstraintViolation $e) {
            if ($e->kind === 'unique') {
                Logger::security('device_replay_rejected', ['device_id' => (int) $device['id']]);
                throw new ApiError('replayed_request', 'This request has already been processed.', 401);
            }
            throw $e;
        }

        Db::exec('UPDATE devices SET last_seen_at = ? WHERE id = ?', [Util::toDb(Util::utcNow()), (int) $device['id']]);

        if (random_int(1, 100) === 1) {
            try {
                $cutoff = Util::toDb(Util::utcNow()->modify('-' . Config::int('security.device_nonce_ttl', 600) . ' seconds'));
                Db::exec('DELETE FROM device_nonces WHERE seen_at < ?', [$cutoff]);
            } catch (\Throwable) {
                // Non-critical maintenance.
            }
        }

        $device['id'] = (int) $device['id'];
        $device['door_id'] = (int) $device['door_id'];
        $device['key_version'] = (int) $device['key_version'];
        $device['is_active'] = (int) $device['is_active'];
        return $device;
    }
}

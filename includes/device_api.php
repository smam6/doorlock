<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Response wrapper for the device-facing authorization endpoint.
 *
 * Unlike the admin API, this returns a flat contract used by the ESP8266
 * firmware. Policy denials are HTTP 200 with `access: false`; authentication
 * and technical failures use 4xx/5xx. No path ever returns `access: true`
 * unless AccessService made a granted decision.
 */
final class DeviceApi
{
    public static function run(callable $handler): void
    {
        $request = Request::fromGlobals();
        try {
            $requestId = $request->requestId();
        } catch (ApiError $e) {
            $requestId = Util::randomHex(16);
            Response::json(self::payload(false, $e->errorCode, $requestId), 400, ['X-Request-Id' => $requestId]);
            return;
        }
        RequestContext::begin($requestId, $request->ip());

        $context = [
            'device' => null,
            'door_id' => null,
            'card_number' => null,
        ];

        ob_start();
        try {
            $result = $handler($request, $context);
            $stray = ob_get_clean();
            if (is_string($stray) && trim($stray) !== '') {
                Logger::error('stray_output_discarded', ['length' => strlen($stray)]);
            }
            Response::json(
                self::payload(
                    (bool) $result['granted'],
                    (string) $result['reason'],
                    $requestId,
                    $result['granted'] ? (int) $result['unlock_seconds'] : null
                ),
                200,
                ['X-Request-Id' => $requestId]
            );
        } catch (ApiError $e) {
            ob_get_clean();
            $decision = $e->status >= 500 ? 'error' : 'denied';
            self::logFailure($request, $context, $requestId, $decision, $e->errorCode);
            Response::json(
                self::payload(false, $e->errorCode, $requestId),
                $e->status,
                array_merge(['X-Request-Id' => $requestId], $e->headers)
            );
        } catch (DatabaseUnavailable $e) {
            ob_get_clean();
            Logger::error('device_database_unavailable', ['exception' => $e, 'device_id' => $context['device']['id'] ?? null]);
            self::logFailure($request, $context, $requestId, 'error', AccessEngine::REASON_SERVICE_UNAVAILABLE);
            Response::json(
                self::payload(false, AccessEngine::REASON_SERVICE_UNAVAILABLE, $requestId),
                503,
                ['X-Request-Id' => $requestId, 'Retry-After' => '5']
            );
        } catch (\Throwable $e) {
            ob_get_clean();
            Logger::error('device_unhandled_exception', ['exception' => $e]);
            self::logFailure($request, $context, $requestId, 'error', AccessEngine::REASON_SERVICE_UNAVAILABLE);
            Response::json(
                self::payload(false, AccessEngine::REASON_SERVICE_UNAVAILABLE, $requestId),
                500,
                ['X-Request-Id' => $requestId]
            );
        }
    }

    /** @return array<string,mixed> */
    private static function payload(bool $access, string $reason, string $requestId, ?int $unlockSeconds = null): array
    {
        $payload = [
            'success' => $access || in_array($reason, [
                AccessEngine::REASON_UNKNOWN_CARD,
                AccessEngine::REASON_CARD_REVOKED,
                AccessEngine::REASON_CARD_LOST,
                AccessEngine::REASON_PERSON_DISABLED,
                AccessEngine::REASON_UNKNOWN_DOOR,
                AccessEngine::REASON_DOOR_INACTIVE,
                AccessEngine::REASON_DEVICE_DOOR_MISMATCH,
                AccessEngine::REASON_NO_PERMISSION,
                AccessEngine::REASON_OUTSIDE_SCHEDULE,
                AccessEngine::REASON_EXPLICIT_DENY,
                AccessEngine::REASON_GRANT_EXPIRED,
                AccessEngine::REASON_GRANT_EXHAUSTED,
                AccessEngine::REASON_GRANT_REVOKED,
            ], true),
            'access' => $access,
            'decision' => $access ? 'granted' : 'denied',
            'reason' => $reason,
            'request_id' => $requestId,
        ];
        if ($access && $unlockSeconds !== null) {
            $payload['unlock_seconds'] = Util::clampInt($unlockSeconds, 1, 30);
        }
        return $payload;
    }

    /** @param array<string,mixed> $context */
    private static function logFailure(Request $request, array $context, string $requestId, string $decision, string $reason): void
    {
        try {
            $device = $context['device'] ?? null;
            AccessLog::insert([
                'request_id' => $requestId,
                'occurred_at' => Util::toDb(Util::utcNow()),
                'person_id' => null,
                'person_name_snapshot' => null,
                'card_id' => null,
                'card_number_snapshot' => $context['card_number'] ?? null,
                'door_id' => null,
                'requested_door_id' => $context['door_id'] ?? null,
                'device_id' => is_array($device) ? (int) $device['id'] : null,
                'decision' => $decision,
                'reason' => $reason,
                'source' => null,
                'unlock_seconds' => null,
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('access_log_write_failed', ['exception' => $e, 'reason' => $reason]);
        }
    }
}

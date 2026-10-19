<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * Immutable-ish wrapper around the current HTTP request.
 */
final class Request
{
    private ?string $rawBody = null;

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $headers,
        private readonly array $query,
        private readonly array $server
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] ??= $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] ??= $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['content-length'] ??= $value;
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            $headers,
            $_GET,
            $_SERVER
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiError::validation($key, 'Query parameter must be a string.');
        }
        return $value;
    }

    public function queryInt(string $key, int $default, int $min, int $max): int
    {
        $value = $this->query($key);
        if ($value === null || $value === '') {
            return $default;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw ApiError::validation($key, 'Query parameter must be an integer.');
        }
        return Util::clampInt((int) $value, $min, $max);
    }

    /** Raw request body, limited to `max_body_bytes`. */
    public function body(): string
    {
        if ($this->rawBody !== null) {
            return $this->rawBody;
        }
        $max = Config::int('max_body_bytes');
        $declared = $this->header('content-length');
        if ($declared !== null && ctype_digit($declared) && (int) $declared > $max) {
            throw new ApiError('payload_too_large', 'Request body is too large.', 413);
        }
        $stream = fopen('php://input', 'rb');
        if ($stream === false) {
            throw new ApiError('invalid_request', 'Unable to read request body.', 400);
        }
        $body = stream_get_contents($stream, $max + 1);
        fclose($stream);
        if ($body === false) {
            throw new ApiError('invalid_request', 'Unable to read request body.', 400);
        }
        if (strlen($body) > $max) {
            throw new ApiError('payload_too_large', 'Request body is too large.', 413);
        }
        $this->rawBody = $body;
        return $body;
    }

    public function json(): array
    {
        $this->requireJsonContentType();
        return Util::jsonDecodeObject($this->body());
    }

    public function requireJsonContentType(): void
    {
        $type = strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
        if ($type !== 'application/json') {
            throw new ApiError('unsupported_media_type', 'Content-Type must be application/json.', 415);
        }
    }

    public function requireMethod(string ...$allowed): void
    {
        if (!in_array($this->method, $allowed, true)) {
            throw ApiError::methodNotAllowed();
        }
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? 'unknown');
        if (Config::bool('trust_proxy')) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null) {
                $candidate = trim(explode(',', $forwarded)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }
        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
    }

    public function isHttps(): bool
    {
        $https = $this->server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        if (Config::bool('trust_proxy')) {
            $proto = strtolower((string) ($this->header('x-forwarded-proto') ?? ''));
            if ($proto === 'https') {
                return true;
            }
        }
        $port = (string) ($this->server['SERVER_PORT'] ?? '');
        return $port === '443';
    }

    public function host(): string
    {
        $host = (string) ($this->header('host') ?? $this->server['SERVER_NAME'] ?? '');
        return strtolower($host);
    }

    /**
     * Correlation id. A client-supplied id must match a strict pattern;
     * otherwise one is generated. Always present and echoed in responses.
     */
    public function requestId(): string
    {
        static $resolved = null;
        if (is_string($resolved)) {
            return $resolved;
        }
        $provided = $this->header('x-request-id');
        if ($provided !== null && $provided !== '') {
            if (!Util::isValidRequestId($provided)) {
                throw new ApiError('invalid_request_id', 'X-Request-Id must match [A-Za-z0-9._-]{8,64}.', 400);
            }
            $resolved = $provided;
        } else {
            $resolved = Util::randomHex(16);
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    public function server(): array
    {
        return $this->server;
    }
}

/** JSON response helpers. */
final class Response
{
    /** @param array<string,string|string[]> $headers */
    public static function json(array $payload, int $status = 200, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            self::applyApiSecurityHeaders();
            foreach ($headers as $name => $value) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        header($name . ': ' . $item, false);
                    }
                } else {
                    header($name . ': ' . $value, true);
                }
            }
        }
        echo Util::jsonEncode($payload);
    }

    /** @param array<string,string> $headers */
    public static function raw(string $body, string $contentType, int $status = 200, array $headers = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . $contentType);
            self::applyApiSecurityHeaders();
            foreach ($headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }
        echo $body;
    }

    public static function applyApiSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        if (Security::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

/** Wraps administrative API handlers: one request, exactly one JSON response. */
final class Api
{
    public static function run(callable $handler): void
    {
        $request = Request::fromGlobals();
        try {
            $requestId = $request->requestId();
        } catch (ApiError $e) {
            $requestId = Util::randomHex(16);
            self::sendError($e, $requestId);
            return;
        }
        RequestContext::begin($requestId, $request->ip());
        Logger::debug('api_request', ['method' => $request->method(), 'path' => $request->path()]);

        ob_start();
        $sent = false;
        try {
            $result = $handler($request);
            if (is_array($result) && isset($result['__raw']) && is_string($result['__raw'])) {
                $stray = ob_get_clean();
                if (is_string($stray) && trim($stray) !== '') {
                    Logger::error('stray_output_discarded', ['length' => strlen($stray)]);
                }
                Response::raw(
                    $result['__raw'],
                    (string) ($result['content_type'] ?? 'text/plain; charset=utf-8'),
                    (int) ($result['status'] ?? 200),
                    (array) ($result['headers'] ?? [])
                );
                $sent = true;
                return;
            }
            $status = 200;
            if (is_array($result) && array_key_exists('data', $result)) {
                $payload = $result['data'];
                $status = (int) ($result['status'] ?? 200);
                $meta = $result['meta'] ?? null;
            } else {
                $payload = $result;
                $meta = null;
            }
            $envelope = ['success' => true, 'data' => $payload, 'request_id' => $requestId];
            if (is_array($meta)) {
                $envelope['meta'] = $meta;
            }
            $stray = ob_get_clean();
            if (is_string($stray) && trim($stray) !== '') {
                Logger::error('stray_output_discarded', ['length' => strlen($stray)]);
            }
            Response::json($envelope, $status, ['X-Request-Id' => $requestId]);
            $sent = true;
        } catch (ApiError $e) {
            $stray = ob_get_clean();
            if (is_string($stray) && trim($stray) !== '') {
                Logger::error('stray_output_discarded', ['length' => strlen($stray)]);
            }
            self::sendError($e, $requestId);
            $sent = true;
        } catch (DatabaseUnavailable $e) {
            ob_get_clean();
            Logger::error('database_unavailable', ['exception' => $e]);
            self::sendError(
                new ApiError('service_unavailable', 'The service is temporarily unavailable. Please try again later.', 503),
                $requestId
            );
            $sent = true;
        } catch (\Throwable $e) {
            ob_get_clean();
            Logger::error('unhandled_exception', ['exception' => $e, 'path' => $request->path()]);
            self::sendError(
                new ApiError('internal_error', 'An internal error occurred. The request was not completed.', 500),
                $requestId
            );
            $sent = true;
        } finally {
            while (ob_get_level() > 0 && !$sent) {
                ob_end_clean();
            }
        }
    }

    private static function sendError(ApiError $error, string $requestId): void
    {
        if (!headers_sent()) {
            $headers = $error->headers;
            $headers['X-Request-Id'] = $requestId;
            if ($error->status === 429 && !isset($headers['Retry-After'])) {
                $headers['Retry-After'] = (string) Config::int('security.login_lockout', 900);
            }
            $payload = [
                'success' => false,
                'error' => [
                    'code' => $error->errorCode,
                    'message' => $error->getMessage(),
                ],
                'request_id' => $requestId,
            ];
            if ($error->fields !== []) {
                $payload['error']['fields'] = $error->fields;
            }
            Response::json($payload, $error->status, $headers);
            return;
        }
        echo Util::jsonEncode([
            'success' => false,
            'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()],
            'request_id' => $requestId,
        ]);
    }

    /**
     * Authenticate the caller and enforce role permissions + CSRF.
     *
     * @return array<string,mixed> authenticated user row
     */
    public static function guard(Request $request, string $readAction, ?string $writeAction = null): array
    {
        $user = Auth::requireUser();
        $method = $request->method();
        $isRead = in_array($method, ['GET', 'HEAD'], true);
        $action = $isRead ? $readAction : ($writeAction ?? $readAction);
        Rbac::require($user, $action);
        if (!$isRead) {
            Security::requireCsrf($request);
            Security::requireSameOrigin($request);
        }
        return $user;
    }
}

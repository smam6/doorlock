<?php

declare(strict_types=1);

namespace DoorLock;

/**
 * An error that is safe to expose to an API client.
 *
 * The message is intentionally generic and stable; internal details belong in
 * the server-side log, never in the HTTP response.
 */
class ApiError extends \RuntimeException
{
    public int $status;
    public string $errorCode;
    /** @var array<string,string> */
    public array $fields;
    /** @var array<string,string> */
    public array $headers;

    /**
     * @param array<string,string> $fields
     * @param array<string,string> $headers
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $status = 400,
        array $fields = [],
        array $headers = []
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
        $this->fields = $fields;
        $this->headers = $headers;
    }

    public static function unauthorized(string $message = 'Authentication required.'): self
    {
        return new self('unauthenticated', $message, 401);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function validation(string $field, string $message): self
    {
        return new self('validation_failed', $message, 400, [$field => $message]);
    }

    public static function methodNotAllowed(): self
    {
        return new self('method_not_allowed', 'Method not allowed.', 405, [], ['Allow' => 'GET, POST, PUT, DELETE']);
    }
}

/** Configuration is missing or invalid. Never shown to clients. */
class ConfigError extends \RuntimeException
{
}

/** The database is unreachable or a query failed unexpectedly. */
class DatabaseUnavailable extends \RuntimeException
{
}

/** A database constraint was violated (duplicate, foreign key, ...). */
class ConstraintViolation extends \RuntimeException
{
    public string $kind; // unique|foreign_key|check|other

    public function __construct(string $message, string $kind = 'other', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->kind = $kind;
    }
}

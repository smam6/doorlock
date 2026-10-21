<?php

declare(strict_types=1);

/**
 * Shared HTTP helper for the browser-level test suites.
 *
 * Requires ext-curl, which the application itself also needs. The base URL is
 * read from DOORLOCK_TEST_BASE_URL so the same suite can run against the
 * development server, a staging host or a production-like environment.
 */

/** Base URL of the server under test, without a trailing slash. */
function httpBaseUrl(): string
{
    return rtrim((string) (getenv('DOORLOCK_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
}

/**
 * Perform one HTTP request and return the status, headers and body.
 *
 * @param array<string,string> $headers
 * @param string|null $jar Cookie jar path (created on first use).
 * @return array{status:int,headers:array<string,string>,body:string}
 */
function http(string $method, string $path, ?string $body = null, array $headers = [], ?string &$jar = null): array
{
    $ch = curl_init(httpBaseUrl() . $path);
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $headerLines,
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    if ($jar !== null) {
        $options[CURLOPT_COOKIEJAR] = $jar;
        $options[CURLOPT_COOKIEFILE] = $jar;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP transport failed: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headerText = substr($raw, 0, $headerSize);
    $responseBody = substr($raw, $headerSize);
    $responseHeaders = [];
    foreach (preg_split('/\r?\n/', $headerText) ?: [] as $line) {
        $position = strpos($line, ':');
        if ($position !== false) {
            $responseHeaders[strtolower(substr($line, 0, $position))] = trim(substr($line, $position + 1));
        }
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
}

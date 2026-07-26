<?php
/**
 * includes/functions.php
 * Shared helper functions: database access wrappers (mysqli-based,
 * matching the $conn connection created in config.php) and the
 * Arduino HTTP bridge.
 */

/**
 * Run a SELECT (or any) query and return all rows as an associative array.
 * Uses prepared statements to stay injection-safe.
 *
 * @param string $sql    SQL with ? placeholders
 * @param array  $params Values to bind, in order
 * @return array List of rows (empty array if none / not a result-returning query)
 */
function dbQuery(string $sql, array $params = []): array {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('dbQuery prepare failed: ' . $conn->error . ' | SQL: ' . $sql);
        return [];
    }
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p)) $types .= 'i';
            elseif (is_float($p)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/**
 * Run a query expected to return a single row (or null if none found).
 */
function dbQuerySingle(string $sql, array $params = []): ?array {
    $rows = dbQuery($sql, $params);
    return $rows[0] ?? null;
}

/**
 * Run an INSERT/UPDATE/DELETE statement that doesn't need to return rows
 * (e.g. INSERT IGNORE, DELETE, UPDATE). Returns true/false for success.
 */
function dbExecute(string $sql, array $params = []): bool {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('dbExecute prepare failed: ' . $conn->error . ' | SQL: ' . $sql);
        return false;
    }
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p)) $types .= 'i';
            elseif (is_float($p)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

/**
 * Run an INSERT statement and return the new row's auto-increment id.
 */
function dbInsert(string $sql, array $params = []): int {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('dbInsert prepare failed: ' . $conn->error . ' | SQL: ' . $sql);
        return 0;
    }
    if ($params) {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p)) $types .= 'i';
            elseif (is_float($p)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return (int)$id;
}

/**
 * Send a command to the Arduino via HTTP.
 * @param string $endpoint e.g. '/control', '/status'
 * @param string $method 'GET' or 'POST'
 * @param array|null $data Associative array for POST data (will be JSON-encoded)
 * @return array|false Response decoded from JSON, or false on failure
 */
function sendToArduino($endpoint, $method = 'GET', $data = null) {
    $url = 'http://' . ARDUINO_IP . ':' . ARDUINO_PORT . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 second timeout

    $headers = ['Content-Type: application/json'];
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            $jsonData = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
            $headers[] = 'Content-Length: ' . strlen($jsonData);
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        error_log("Arduino communication failed: $httpCode - $response");
        return false;
    }

    return json_decode($response, true);
}

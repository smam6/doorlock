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
        // Log error or return false
        error_log("Arduino communication failed: $httpCode - $response");
        return false;
    }

    return json_decode($response, true);
}
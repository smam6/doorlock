<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['check'])) {
    // Session check
    if (isset($_SESSION['user_id'])) {
        echo json_encode(['authenticated' => true, 'user' => ['id' => $_SESSION['user_id'], 'username' => $_SESSION['username']]]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = (string)($input['password'] ?? '');

    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'error' => 'نام کاربری و رمز عبور الزامی است']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id, username, password_hash FROM web_users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Prevent session fixation
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        echo json_encode(['success' => true, 'user' => ['id' => (int)$user['id'], 'username' => $user['username']]]);
    } else {
        // Same generic message whether the username or the password was wrong,
        // so a caller can't use this endpoint to enumerate valid usernames.
        echo json_encode(['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

<?php
session_start();
header('Content-Type: application/json');

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
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    // Dummy authentication (replace with database check)
    if ($username === 'admin' && $password === '1234') {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        echo json_encode(['success' => true, 'user' => ['id' => 1, 'username' => 'admin']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
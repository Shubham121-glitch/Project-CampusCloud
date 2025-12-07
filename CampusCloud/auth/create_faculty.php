<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin']);
include_once __DIR__ . '/../db/connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $user_id = intval(trim($_POST['user_id'] ?? 0));
    $pin = intval(trim($_POST['pin'] ?? 0));

    // Validate inputs
    if (!$name || strlen($name) < 2) {
        echo json_encode(['success' => false, 'message' => 'Invalid name']);
        exit;
    }

    if ($user_id < 100000 || $user_id > 999999) {
        echo json_encode(['success' => false, 'message' => 'Invalid user_id']);
        exit;
    }

    if ($pin < 1000 || $pin > 9999) {
        echo json_encode(['success' => false, 'message' => 'Invalid PIN']);
        exit;
    }

    // Check if user_id already exists
    $checkQuery = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($checkQuery, 'i', $user_id);
    mysqli_stmt_execute($checkQuery);
    $result = mysqli_stmt_get_result($checkQuery);
    
    if (mysqli_num_rows($result) > 0) {
        echo json_encode(['success' => false, 'message' => 'User ID already exists']);
        exit;
    }

    // Insert faculty (role = 'moderator')
    $insertQuery = mysqli_prepare($conn, "INSERT INTO users (user_id, username, pin, role) VALUES (?, ?, ?, ?)");
    $role = 'moderator';
    mysqli_stmt_bind_param($insertQuery, 'isss', $user_id, $name, $pin, $role);
    
    if (mysqli_stmt_execute($insertQuery)) {
        echo json_encode([
            'success' => true,
            'message' => 'Faculty created successfully',
            'user_id' => $user_id,
            'pin' => $pin,
            'name' => $name
        ]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>

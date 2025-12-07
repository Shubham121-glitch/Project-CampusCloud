<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin']);
include_once __DIR__ . '/../db/connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = intval(trim($_POST['user_id'] ?? 0));

    if ($user_id < 100000 || $user_id > 999999) {
        echo json_encode(['exists' => true, 'error' => 'Invalid user_id']);
        exit;
    }

    $query = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($query, 'i', $user_id);
    mysqli_stmt_execute($query);
    $result = mysqli_stmt_get_result($query);
    $exists = mysqli_num_rows($result) > 0;

    echo json_encode(['exists' => $exists]);
    exit;
}

echo json_encode(['error' => 'Invalid request']);
?>

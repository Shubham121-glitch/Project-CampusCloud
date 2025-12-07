<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator', 'user']);
include __DIR__ . '/../db/connection.php';

header('Content-Type: application/json');

if (!isset($_GET['id']) || !isset($_GET['table'])) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$student_id = intval($_GET['id']);
$table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']); // Sanitize table name

// Fetch student data
$query = "SELECT * FROM `" . $table . "` WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $student_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $student = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
    
    if ($student) {
        echo json_encode($student);
    } else {
        echo json_encode(['error' => 'Student not found']);
    }
} else {
    echo json_encode(['error' => 'Database error']);
}
?>

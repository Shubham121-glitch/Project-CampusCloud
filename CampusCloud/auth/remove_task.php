<?php
session_start();

// Allow only admin or moderator
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'moderator'])) {
    die("Unauthorized access");
}

include_once __DIR__ . '/../db/connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $task_id = intval($_POST['task_id']);

    if (!$task_id) {
        die("Invalid request");
    }

    $stmt = $conn->prepare("DELETE FROM tasks WHERE id = ?");
    $stmt->bind_param("i", $task_id);

    if ($stmt->execute()) {
        header("Location: dashboard.php?msg=Task Removed");
        exit;
    } else {
        echo "Failed to delete task!";
    }

    $stmt->close();
}

$conn->close();
?>

<?php
session_start();
include './require_role.php';
require_roles(['admin']); // Only admin can delete

include '../db/connection.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid request.");
}

$user_id = (int)$_GET['id'];

// First, get username for confirmation message
$stmt = $conn->prepare("SELECT username FROM users WHERE user_id = ? AND role = 'moderator'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Faculty member not found.";
    header("Location: faculty.php");
    exit;
}

$user = $result->fetch_assoc();
$username = $user['username'];

// Now delete
$delete_stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'moderator'");
$delete_stmt->bind_param("i", $user_id);

if ($delete_stmt->execute()) {
    $_SESSION['success'] = "Faculty member <strong>$username</strong> deleted successfully.";
} else {
    $_SESSION['error'] = "Failed to delete faculty member.";
}

$delete_stmt->close();
$stmt->close();
$conn->close();

header("Location: faculty.php");
exit;
?>
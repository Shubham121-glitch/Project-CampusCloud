<?php
session_start();
include './require_role.php';
require_roles(['admin']);

include '../db/connection.php';

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

// Fetch current user
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
$current_username = $user['username'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_username = trim($_POST['username']);

    if (empty($new_username)) {
        $message = "<p style='color:var(--danger);'>Username cannot be empty.</p>";
    } elseif ($new_username === $current_username) {
        $message = "<p style='color:var(--warning);'>No changes made.</p>";
    } else {
        // Check if username already exists
        $check = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
        $check->bind_param("si", $new_username, $user_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $message = "<p style='color:var(--danger);'>Username already taken.</p>";
        } else {
            $update = $conn->prepare("UPDATE users SET username = ? WHERE user_id = ?");
            $update->bind_param("si", $new_username, $user_id);
            if ($update->execute()) {
                $_SESSION['success'] = "Username updated to <strong>$new_username</strong> successfully.";
                header("Location: faculty.php");
                exit;
            } else {
                $message = "<p style='color:var(--danger);'>Update failed.</p>";
            }
            $update->close();
        }
        $check->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit Faculty — CampusCloud</title>
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="styles/courses.css">
  <script defer src="styles/theme.js"></script>
  <style>
    .edit-card {
      max-width: 560px;
      margin: 40px auto;
      background: var(--surface);
      padding: 32px;
      border-radius: var(--radius-xl);
      border: 1px solid var(--border);
      box-shadow: var(--shadow-lg);
    }
    .form-group { margin-bottom: 24px; }
    label { display: block; margin-bottom: 8px; font-weight: 600; color: var(--text); }
    input[type="text"] {
      width: 100%;
      color: var(--text);
      padding: 14px 16px;
      border: 2px solid var(--border);
      border-radius: var(--radius-lg);
      background: var(--glass);
      font-size: 1rem;
      transition: all 0.3s ease;
    }
    input[type="text"]:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 4px var(--primary-glow);
    }
    .btn-group { display: flex; gap: 12px; margin-top: 32px; }
  </style>
</head>
<body>

<div class="container">
  <header class="page-header" style="margin-bottom:32px;">
    <div>
      <h1 class="page-title">Edit Faculty Username</h1>
      <div class="muted">You can only change the username. PIN and role are fixed.</div>
    </div>
    <a href="faculty.php" class="btn btn-small btn-outline">Back to List</a>
  </header>

  <div class="edit-card">
    <form method="POST">
      <div class="form-group">
        <label for="username">Faculty Username</label>
        <input type="text" 
               id="username" 
               name="username" 
               value="<?= htmlspecialchars($current_username) ?>" 
               required 
               autocomplete="off"
               placeholder="Enter new username">
      </div>

      <?= $message ?>

      <div class="btn-group">
        <button type="submit" class="btn btn-primary" style="flex:1;">
          Save Changes
        </button>
        <a href="faculty.php" class="btn btn-outline" style="flex:1;">
          Cancel
        </a>
      </div>
    </form>
  </div>
</div>

</body>
</html>
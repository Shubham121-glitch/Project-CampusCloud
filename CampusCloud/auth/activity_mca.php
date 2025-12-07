<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin','moderator','user']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Activity — MCA</title>
<link rel="stylesheet" href="styles/main.css">
<script defer src="styles/theme.js"></script>
</head>
<body>
<header style="display:flex;justify-content:space-between;align-items:center">
    <div><strong>CampusCloud</strong></div>
    <div>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php" style="color:var(--danger)">Logout</a>
    </div>
</header>
<main style="margin-top:18px">
    <h2>MCA Activity</h2>
    <p>Placeholder: Student activity status (viewable by all roles).</p>
</main>
</body>
</html>

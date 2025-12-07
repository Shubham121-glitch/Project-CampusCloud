<?php
session_start();
// Redirect role-specific admin panel to the unified dashboard
header('Location: dashboard.php');
exit;
?>

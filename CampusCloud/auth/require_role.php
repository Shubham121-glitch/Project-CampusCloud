<?php
/**
 * require_role.php
 * Small helper to require one or more roles for access.
 * Usage:
 *   include_once 'require_role.php';
 *   require_roles(['admin','moderator','user']);
 */
function require_roles(array $allowedRoles)
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        header('Location: landing.php');
        exit;
    }

    $role = $_SESSION['role'];
    // strict comparison
    foreach ($allowedRoles as $r) {
        if ($r === $role) {
            return; // allowed
        }
    }

    // not allowed
    header('Location: landing.php');
    exit;
}

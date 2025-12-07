<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator']);
include __DIR__ . '/../db/connection.php';
$currentUserId = $_SESSION['user_id'] ?? 0;
$currentRole = $_SESSION['role'] ?? '';

// Handle printable export (admins see all; moderators see only their submissions)
if (isset($_GET['export']) && $_GET['export'] === 'print') {
    if ($currentRole === 'moderator') {
        $pstmt = mysqli_prepare($conn, "SELECT t.*, u.username AS submitter_name, td.*
            FROM tasks t
            LEFT JOIN users u ON t.submitted_by = u.user_id
            LEFT JOIN task_details td ON t.id = td.task_id
            WHERE t.submitted_by = ?
            ORDER BY t.created_at DESC");
        mysqli_stmt_bind_param($pstmt, 'i', $currentUserId);
        mysqli_stmt_execute($pstmt);
        $pres = mysqli_stmt_get_result($pstmt);
        $printRows = mysqli_fetch_all($pres, MYSQLI_ASSOC);
        mysqli_stmt_close($pstmt);
    } else {
        $printQuery = "SELECT t.*, u.username AS submitter_name, td.*
              FROM tasks t
              LEFT JOIN users u ON t.submitted_by = u.user_id
              LEFT JOIN task_details td ON t.id = td.task_id
              ORDER BY t.created_at DESC";
        $printResult = mysqli_query($conn, $printQuery);
        $printRows = mysqli_fetch_all($printResult, MYSQLI_ASSOC);
    }
    ?>
    <!doctype html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Printable Tasks History</title>
        <style>
            body { font-family: Arial, Helvetica, sans-serif; color: #111; }
            table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 12px; }
            th { background: #f7f7f7; }
        </style>
    </head>
    <body>
        <h2>Tasks Transaction History</h2>
        <p>Downloaded: <?php echo date('M d, Y H:i'); ?></p>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Course Code</th>
                    <th>Subject</th>
                    <th>Table</th>
                    <th>Submitted By</th>
                    <th>Course Status</th>
                    <th>Task Status</th>
                    <th>Submitted At</th>
                    <th>Updated At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($printRows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['id']); ?></td>
                        <td><?php echo htmlspecialchars(strtoupper($r['task_type'])); ?></td>
                        <td><?php echo htmlspecialchars($r['course_code']); ?></td>
                        <td><?php echo htmlspecialchars($r['subject']); ?></td>
                        <td><?php echo htmlspecialchars($r['table_name']); ?></td>
                        <td><?php echo htmlspecialchars($r['submitter_name'] ?? $r['submitted_by_user'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($r['status']); ?></td>
                        <td><?php echo htmlspecialchars($r['task_status'] ?? ''); ?></td>
                        <td><?php echo date('M d, Y H:i', strtotime($r['created_at'])); ?></td>
                        <td><?php echo date('M d, Y H:i', strtotime($r['updated_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}

// Fetch tasks with details
if ($currentRole === 'moderator') {
    $stmt = mysqli_prepare($conn, "SELECT t.*, u.username as submitted_by_name, td.*
          FROM tasks t
          LEFT JOIN users u ON t.submitted_by = u.user_id
          LEFT JOIN task_details td ON t.id = td.task_id
          WHERE t.submitted_by = ?
          ORDER BY t.created_at DESC");
    mysqli_stmt_bind_param($stmt, 'i', $currentUserId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} else {
    $query = "SELECT t.*, u.username as submitted_by_name, td.*
          FROM tasks t
          LEFT JOIN users u ON t.submitted_by = u.user_id
          LEFT JOIN task_details td ON t.id = td.task_id
          ORDER BY t.created_at DESC";
    $result = mysqli_query($conn, $query);
    $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tasks History — CampusCloud</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/tasks_history.css">
    <script defer src="styles/theme.js"></script>
</head>

<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title">📋 Tasks Transaction History</h1>
                <p class="muted">Complete log of all course request submissions and approvals</p>
            </div>
            <a class="btn btn-outline" href="dashboard.php">← Back to Dashboard</a>
        </header>

        <main>
            <div class="form-card">
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
                    <a class="btn btn-primary" href="tasks_history.php?export=print" target="_blank">🖨️ Print / Save as PDF</a>
                    <input type="text" id="search-box" class="search-box" placeholder="🔍 Search by course code, subject, or status..." onkeyup="filterTasks()">
                </div>

                <?php if (empty($rows)): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <p>📭 No transaction history yet</p>
                    </div>
                <?php else: ?>
                    <div class="history-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Type</th>
                                    <th>Course Code</th>
                                    <th>Subject</th>
                                    <th>Table</th>
                                    <th>Submitted By</th>
                                    <th>Status</th>
                                    <th>Submitted At</th>
                                    <th>Updated At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['id']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $r['task_type'] === 'add' ? 'badge-add' : 'badge-edit'; ?>">
                                                <?php echo strtoupper($r['task_type']); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($r['course_code'] ?? 'N/A'); ?></strong></td>
                                        <td><?php echo htmlspecialchars($r['subject'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($r['table_name']); ?></td>
                                        <td><?php echo htmlspecialchars($r['submitted_by_name'] ?? 'Unknown'); ?></td>
                                        <td>
                                            <span class="badge <?php echo $r['status'] === 'pending' ? 'badge-pending' : ($r['status'] === 'approved' ? 'badge-approved' : 'badge-rejected'); ?>">
                                                <?php echo ucfirst($r['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y H:i', strtotime($r['created_at'])); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($r['updated_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function filterTasks() {
            const searchTerm = document.getElementById('search-box').value.toLowerCase();
            const table = document.querySelector('table tbody');
            if (!table) return;
            
            const rows = table.querySelectorAll('tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }
    </script>
</body>

</html>
<?php
// Admin UI: review and approve/reject internal mark requests
include_once __DIR__ . '/require_role.php';
require_roles(['admin']);
include __DIR__ . '/../db/connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$message = '';
$messageType = 'success';

// Handle approve/reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['request_id'])) {
    $action = $_POST['action'];
    $reqId = intval($_POST['request_id']);

    if ($action === 'approve') {
        // select request
        $s = mysqli_prepare($conn, "SELECT term, course_table, course_id, student_table, student_id, proposed_marks FROM internal_mark_requests WHERE id = ? AND status = 'pending' LIMIT 1");
        if ($s) {
            mysqli_stmt_bind_param($s, 'i', $reqId);
            mysqli_stmt_execute($s);
            mysqli_stmt_store_result($s);
            if (mysqli_stmt_num_rows($s) === 0) {
                $message = 'Request not found or already processed.';
                $messageType = 'error';
            } else {
                mysqli_stmt_bind_result($s, $term, $course_table, $course_id, $student_table, $student_id, $proposed_marks);
                mysqli_stmt_fetch($s);
                mysqli_stmt_close($s);

                // upsert into internal_marks
                $up = mysqli_prepare($conn, "INSERT INTO internal_marks (term, course_table, course_id, student_table, student_id, marks) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE marks = VALUES(marks), updated_at = CURRENT_TIMESTAMP");
                if ($up) {
                    mysqli_stmt_bind_param($up, 'ssissi', $term, $course_table, $course_id, $student_table, $student_id, $proposed_marks);
                    mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);
                }

                // mark request approved
                $u = mysqli_prepare($conn, "UPDATE internal_mark_requests SET status='approved' WHERE id = ?");
                if ($u) {
                    mysqli_stmt_bind_param($u, 'i', $reqId);
                    mysqli_stmt_execute($u);
                    mysqli_stmt_close($u);
                }

                $message = 'Request approved and marks updated.';
                $messageType = 'success';
            }
        }
    } elseif ($action === 'reject') {
        $u = mysqli_prepare($conn, "UPDATE internal_mark_requests SET status='rejected' WHERE id = ? AND status = 'pending'");
        if ($u) {
            mysqli_stmt_bind_param($u, 'i', $reqId);
            mysqli_stmt_execute($u);
            if (mysqli_stmt_affected_rows($u) > 0) {
                $message = 'Request rejected.';
                $messageType = 'success';
            } else {
                $message = 'Request not found or already processed.';
                $messageType = 'error';
            }
            mysqli_stmt_close($u);
        }
    }

    // redirect to avoid reposts
    header('Location: internal_mark_approvals.php?msg=' . urlencode($message) . '&t=' . urlencode($messageType));
    exit;
}

// Fetch pending requests
$query = "SELECT imr.id, imr.term, imr.course_table, imr.course_id, imr.student_table, imr.student_id, imr.proposed_marks, imr.submitted_by, imr.created_at,
    s.name as student_name, c.course_code, c.subject
    FROM internal_mark_requests imr
    LEFT JOIN (
        SELECT id, name, 'bca_i' as t FROM bca_student_i
        UNION SELECT id, name, 'bca_ii' FROM bca_student_ii
        UNION SELECT id, name, 'bca_iii' FROM bca_student_iii
        UNION SELECT id, name, 'bca_iv' FROM bca_student_iv
        UNION SELECT id, name, 'bca_v' FROM bca_student_v
        UNION SELECT id, name, 'bca_vi' FROM bca_student_vi
        UNION SELECT id, name, 'mca_i' FROM mca_student_i
        UNION SELECT id, name, 'mca_ii' FROM mca_student_ii
        UNION SELECT id, name, 'mca_iii' FROM mca_student_iii
        UNION SELECT id, name, 'mca_iv' FROM mca_student_iv
        UNION SELECT id, name, 'mca_v' FROM mca_student_v
        UNION SELECT id, name, 'mca_vi' FROM mca_student_vi
    ) s ON (s.id = imr.student_id AND s.t = imr.student_table)
    LEFT JOIN (
        SELECT id, course_code, subject, 'bca_i' as t FROM bca_i
        UNION SELECT id, course_code, subject, 'bca_ii' FROM bca_ii
        UNION SELECT id, course_code, subject, 'bca_iii' FROM bca_iii
        UNION SELECT id, course_code, subject, 'bca_iv' FROM bca_iv
        UNION SELECT id, course_code, subject, 'bca_v' FROM bca_v
        UNION SELECT id, course_code, subject, 'bca_vi' FROM bca_vi
        UNION SELECT id, course_code, subject, 'mca_i' FROM mca_i
        UNION SELECT id, course_code, subject, 'mca_ii' FROM mca_ii
        UNION SELECT id, course_code, subject, 'mca_iii' FROM mca_iii
        UNION SELECT id, course_code, subject, 'mca_iv' FROM mca_iv
        UNION SELECT id, course_code, subject, 'mca_v' FROM mca_v
        UNION SELECT id, course_code, subject, 'mca_vi' FROM mca_vi
    ) c ON (c.id = imr.course_id AND c.t = imr.course_table)
    WHERE imr.status = 'pending' ORDER BY imr.created_at ASC";

$res = mysqli_query($conn, $query);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Internal Marks — Approvals</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/internal.css">
    <script defer src="styles/theme.js"></script>
</head>
<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title">Pending Internal Mark Requests</h1>
                <div class="muted">Approve or reject proposed internal marks</div>
            </div>
            <div>
                <a class="btn btn-outline" href="dashboard.php">Back</a>
                <a class="btn btn-primary" href="logout.php">Logout</a>
            </div>
        </header>

        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-<?php echo htmlspecialchars($_GET['t'] ?? 'success'); ?>"><?php echo htmlspecialchars($_GET['msg']); ?></div>
        <?php endif; ?>

        <main>
            <?php if (!$res || mysqli_num_rows($res) === 0): ?>
                <div class="card">No pending requests.</div>
            <?php else: ?>
                <div class="table-responsive card">
                    <table class="table">
                        <thead>
                            <tr><th>When</th><th>Term</th><th>Course</th><th>Student</th><th>Proposed</th><th>By</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        <?php while ($r = mysqli_fetch_assoc($res)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($r['term']); ?></td>
                                <td><?php echo htmlspecialchars($r['course_code'] ?? $r['course_table']) . ' — ' . htmlspecialchars($r['subject'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($r['student_name'] ?? ($r['student_table'].'#'.$r['student_id'])); ?></td>
                                <td><?php echo intval($r['proposed_marks']); ?></td>
                                <td><?php echo htmlspecialchars($r['submitted_by']); ?></td>
                                <td>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="request_id" value="<?php echo intval($r['id']); ?>">
                                        <button class="btn btn-success" name="action" value="approve" onclick="return confirm('Approve this request?')">Approve</button>
                                    </form>
                                    <form method="post" style="display:inline">
                                        <input type="hidden" name="request_id" value="<?php echo intval($r['id']); ?>">
                                        <button class="btn btn-danger" name="action" value="reject" onclick="return confirm('Reject this request?')">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>

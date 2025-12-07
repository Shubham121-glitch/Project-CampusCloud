<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator', 'user']);
include __DIR__ . '/../db/connection.php';

// Debug flag: append &debug=1 to the delete URL to show DB errors inline
$debug_mode = isset($_GET['debug']) && $_GET['debug'] === '1';

// Simple logger for debugging DB/flow issues
function dc_log($message)
{
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $file = $logDir . '/delete_course_debug.log';
    $time = date('Y-m-d H:i:s');
    @file_put_contents($file, "[$time] " . $message . "\n", FILE_APPEND | LOCK_EX);
}

function handle_error_and_exit($userMessage, $debugDetail = null, $debug_mode = false)
{
    if ($debugDetail) {
        dc_log($debugDetail);
    }
    if ($debug_mode) {
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR: " . $userMessage . "\n";
        if ($debugDetail) {
            echo "DETAIL: " . $debugDetail . "\n";
        }
        exit;
    }
    header('Location: courses_bca.php?msg=' . urlencode($userMessage) . '&t=danger');
    exit;
}

if (!isset($_GET['table']) || !isset($_GET['id'])) {
    die("Missing parameters.");
}

$table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']); // Sanitize
$course_id = intval($_GET['id']);
$submitted_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

// Ensure we have a submitting user id
if (empty($submitted_by)) {
    handle_error_and_exit('You must be logged in to submit a delete request.', 'Missing session user_id', $debug_mode);
}

// Check for existing pending delete task
$check = mysqli_prepare($conn, "SELECT id FROM tasks WHERE table_name = ? AND course_id = ? AND task_type = 'delete' AND status = 'pending'");
if ($check) {
    mysqli_stmt_bind_param($check, 'si', $table, $course_id);
    mysqli_stmt_execute($check);
    mysqli_stmt_store_result($check);
    $pending = mysqli_stmt_num_rows($check);
    mysqli_stmt_close($check);
    if ($pending > 0) {
        header('Location: courses_bca.php?msg=' . urlencode('A delete request for this course is already pending approval.') . '&t=warning');
        exit;
    }
} else {
    handle_error_and_exit('Database error while checking pending tasks.', 'prepare(tasks select) failed: ' . mysqli_error($conn), $debug_mode);
}

// Fetch course snapshot
$fetch = mysqli_prepare($conn, "SELECT course_code, subject, course_type, credits, internal_marks, external_marks, instructor, not_in_use FROM `" . $table . "` WHERE id = ?");
if (!$fetch) {
    handle_error_and_exit('Database error while fetching course snapshot.', 'prepare(fetch course) failed: ' . mysqli_error($conn), $debug_mode);
}

mysqli_stmt_bind_param($fetch, 'i', $course_id);
mysqli_stmt_execute($fetch);
    mysqli_stmt_store_result($fetch);
    if (mysqli_stmt_num_rows($fetch) === 0) {
    mysqli_stmt_close($fetch);
    handle_error_and_exit('Course not found.', 'No row found in ' . $table . ' for id=' . $course_id, $debug_mode);
    exit;
}

mysqli_stmt_bind_result($fetch, $course_code, $subject, $course_type, $credits, $internal_marks, $external_marks, $instructor, $not_in_use);
mysqli_stmt_fetch($fetch);
mysqli_stmt_close($fetch);

// New flow: require typed confirmation and perform immediate deletion
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Show a styled confirmation form
    $safe_code = htmlspecialchars($course_code ?? '', ENT_QUOTES, 'UTF-8');
    $safe_subject = htmlspecialchars($subject ?? '', ENT_QUOTES, 'UTF-8');
    $action = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
    $tableEsc = htmlspecialchars($table, ENT_QUOTES, 'UTF-8');
    $idEsc = intval($course_id);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Confirm Delete</title>';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link rel="stylesheet" href="styles/main.css">';
    echo '<script defer src="styles/theme.js"></script>';
    echo '<style>
        body{background:var(--bg);color:var(--text);margin:0;padding:24px}
        .dc-navbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px}
        .dc-logo{font-size:1rem;font-weight:700;color:var(--primary)}
        .dc-theme-btn{background:transparent;border:1px solid var(--border);border-radius:50%;width:40px;height:40px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1.2rem}
        .dc-container{max-width:760px;margin:0 auto}
        .dc-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:32px;box-shadow:var(--shadow-md)}
        .dc-header{margin-bottom:24px}
        .dc-title{font-size:24px;font-weight:700;color:var(--text);margin:0 0 6px 0}
        .dc-sub{color:var(--muted);font-size:14px;margin:0}
        .course-line{margin:20px 0;padding:16px;background:var(--surface-2);border-radius:10px;border:1px solid var(--border)}
        .course-line strong{color:var(--primary);font-weight:600}
        pre.phrase{background:var(--text);color:var(--surface);padding:12px;border-radius:8px;display:inline-block;font-size:14px}
        .dc-form{margin:24px 0;display:flex;gap:12px;align-items:center}
        input[type=text].confirm{flex:1;padding:12px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:var(--surface);color:var(--text)}
        input[type=text].confirm:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-glow)}
        button.btn-delete{background:var(--danger);color:white;border:none;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600}
        button.btn-delete:hover{opacity:0.9}
        a.btn-cancel{background:transparent;color:var(--primary);border:1px solid var(--border);padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600}
        a.btn-cancel:hover{background:var(--surface-2)}
        .note{color:var(--muted);font-size:13px;line-height:1.6}
        @media (max-width:520px){body{padding:12px}.dc-card{padding:20px}.dc-title{font-size:18px}.dc-form{flex-direction:column}.dc-form button,.dc-form a{width:100%}}
    </style>';
    echo '</head><body>';
    echo '<div class="dc-navbar"><span class="dc-logo">CampusCloud</span><button id="theme-toggle" class="dc-theme-btn" aria-label="Toggle theme">☀️</button></div>';
    echo '<div class="dc-container"><div class="dc-card">';
    echo '<div class="dc-header"><div><div class="dc-title">Confirm Permanent Deletion</div><div class="dc-sub">This action cannot be undone.</div></div></div>';
    echo '<div class="course-line"><strong>' . $safe_code . '</strong> &mdash; ' . $safe_subject . '</div>';
    echo '<p class="note">To prevent accidental deletions, type the confirmation phrase exactly (case-insensitive) and click <strong>Delete Permanently</strong>. Once deleted, this course cannot be recovered.</p>';
    echo '<p><pre class="phrase">yes i want to delete it</pre></p>';
    echo '<form method="post" action="' . $action . '">';
    echo '<input type="hidden" name="table" value="' . $tableEsc . '">';
    echo '<input type="hidden" name="id" value="' . $idEsc . '">';
    echo '<div class="dc-form">';
    echo '<input class="confirm" type="text" name="confirm_text" placeholder="Type the confirmation phrase here">';
    echo '<button class="btn-delete" type="submit">Delete Permanently</button>';
    echo '<a class="btn-cancel" href="courses_bca.php">Cancel</a>';
    echo '</div>';
    echo '<p class="note">If you prefer the old approval workflow, contact an administrator.</p>';
    echo '</form></div></div>';
    echo '<script src="../auth/styles/theme.js"><\/script>';
    echo '</body></html>';
    exit;
}

// POST: perform confirmation and deletion
$confirm = isset($_POST['confirm_text']) ? trim($_POST['confirm_text']) : '';
if (strtolower($confirm) !== 'yes i want to delete it') {
    handle_error_and_exit('Confirmation phrase incorrect. Deletion aborted.', 'User provided confirmation: ' . $confirm, $debug_mode);
}

// Attempt to delete course row
$del = mysqli_prepare($conn, "DELETE FROM `" . $table . "` WHERE id = ?");
if (!$del) {
    handle_error_and_exit('Database error preparing delete.', 'prepare(delete) failed: ' . mysqli_error($conn), $debug_mode);
}
mysqli_stmt_bind_param($del, 'i', $course_id);
if (!mysqli_stmt_execute($del)) {
    $err = 'execute(delete) failed: ' . mysqli_error($conn);
    mysqli_stmt_close($del);
    handle_error_and_exit('Failed to delete course.', $err, $debug_mode);
}
$affected = mysqli_stmt_affected_rows($del);
mysqli_stmt_close($del);
if ($affected <= 0) {
    handle_error_and_exit('No row deleted. Course may not exist.', 'delete affected rows=' . $affected, $debug_mode);
}

dc_log('Course deleted: table=' . $table . ' id=' . $course_id . ' by user=' . $submitted_by);

header('Location: courses_bca.php?msg=' . urlencode('Course deleted successfully.') . '&t=success');
exit;

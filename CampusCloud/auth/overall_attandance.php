<?php
// Date-wise attendance view + edit
// Users see only attendance they submitted and can edit those rows.
// Admin can see all attendance and can edit any row.
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator', 'user']);
include __DIR__ . '/../db/connection.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
$currentUser = $_SESSION['username'];
$currentRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'] ?? 0;

// Handle AJAX update requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_attendance') {
    header('Content-Type: application/json');
    $id = intval($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!in_array($status, ['present', 'absent', 'leave'])) {
        echo json_encode(['error' => 'Invalid status']);
        exit;
    }

    // Fetch the row to check permission
    $q = mysqli_prepare($conn, "SELECT submitted_by FROM attendance_records WHERE id = ? LIMIT 1");
    if (!$q) {
        echo json_encode(['error' => 'DB error']);
        exit;
    }
    mysqli_stmt_bind_param($q, 'i', $id);
    mysqli_stmt_execute($q);
    mysqli_stmt_bind_result($q, $submitted_by);
    if (!mysqli_stmt_fetch($q)) {
        mysqli_stmt_close($q);
        echo json_encode(['error' => 'Record not found']);
        exit;
    }
    mysqli_stmt_close($q);

    if ($currentRole !== 'admin' && intval($submitted_by) !== intval($currentUserId)) {
        echo json_encode(['error' => 'Not permitted to edit this record']);
        exit;
    }

    $u = mysqli_prepare($conn, "UPDATE attendance_records SET status = ?, submitted_by = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?");
    if (!$u) {
        echo json_encode(['error' => 'DB prepare failed']);
        exit;
    }
    mysqli_stmt_bind_param($u, 'sii', $status, $currentUserId, $id);
    if (!mysqli_stmt_execute($u)) {
        mysqli_stmt_close($u);
        echo json_encode(['error' => 'Update failed']);
        exit;
    }
    mysqli_stmt_close($u);
    echo json_encode(['ok' => true]);
    exit;
}

// Helpers
function getCourseInfo($conn, $course_table, $course_id)
{
    $course_table = preg_replace('/[^a-zA-Z0-9_]/', '', $course_table);
    $q = "SELECT course_code, subject FROM `" . $course_table . "` WHERE id = ? LIMIT 1";
    $s = mysqli_prepare($conn, $q);
    if (!$s)
        return ['code' => '', 'subject' => ''];
    mysqli_stmt_bind_param($s, 'i', $course_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $code, $subject);
    if (mysqli_stmt_fetch($s)) {
        mysqli_stmt_close($s);
        return ['code' => $code, 'subject' => $subject];
    }
    mysqli_stmt_close($s);
    return ['code' => '', 'subject' => ''];
}

function getStudentInfo($conn, $student_table, $student_id)
{
    $student_table = preg_replace('/[^a-zA-Z0-9_]/', '', $student_table);
    $q = "SELECT name, exam_roll_no FROM `" . $student_table . "` WHERE id = ? LIMIT 1";
    $s = mysqli_prepare($conn, $q);
    if (!$s)
        return ['name' => '', 'exam_roll_no' => ''];
    mysqli_stmt_bind_param($s, 'i', $student_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $name, $exam_roll);
    if (mysqli_stmt_fetch($s)) {
        mysqli_stmt_close($s);
        return ['name' => $name, 'exam_roll_no' => $exam_roll];
    }
    mysqli_stmt_close($s);
    return ['name' => '', 'exam_roll_no' => ''];
}

$filter_date = trim($_GET['date'] ?? '');
if ($filter_date === '')
    $filter_date = date('Y-m-d');

// Build query: admin sees all, others see only rows they submitted
// Fetch attendance rows for the date (we'll group and filter in PHP)
$sql = "SELECT ar.id, ar.term, ar.course_table, ar.course_id, ar.student_table, ar.student_id, ar.att_date, ar.status, ar.submitted_by, u.username as submitter
  FROM attendance_records ar LEFT JOIN users u ON ar.submitted_by = u.user_id WHERE ar.att_date = ? ORDER BY ar.course_table, ar.course_id, ar.student_id";
$stmt = mysqli_prepare($conn, $sql);
$result = false;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 's', $filter_date);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
}

// Build grouped structure: [term][course_table::course_id] => rows[]
$grouped = [];
if ($result) {
    while ($r = mysqli_fetch_assoc($result)) {
        $key = $r['term'] . '||' . $r['course_table'] . '::' . $r['course_id'];
        if (!isset($grouped[$key]))
            $grouped[$key] = ['term' => $r['term'], 'course_table' => $r['course_table'], 'course_id' => $r['course_id'], 'rows' => []];
        $grouped[$key]['rows'][] = $r;
    }
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Overall Attendance (Date-wise)</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/attendance.css">
    <script defer src="styles/theme.js"></script>
    <script>
        const filterDate = '<?php echo htmlspecialchars($filter_date); ?>';
        async function updateRow(id) {
            const sel = document.querySelector('select[data-id="' + id + '"]');
            if (!sel) return;
            const status = sel.value;
            const prevStatus = sel.getAttribute('data-prev');
            const fd = new FormData(); fd.append('action', 'update_attendance'); fd.append('id', id); fd.append('status', status); fd.append('date', filterDate);
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const j = await res.json();
            if (j.error) { alert('Update failed: ' + j.error); sel.value = prevStatus; return; }
            sel.setAttribute('data-prev', status);
            sel.classList.add('saved-highlight');
            setTimeout(() => sel.classList.remove('saved-highlight'), 1000);
        }
    </script>
</head>

<body>
    <div class="attendance-container">
        <header class="page-header">
            <div>
                <h1 class="page-title">Attendance by Date</h1>
                <div class="page-sub muted">Filter by date — edit rows you submitted (admin can edit all)</div>
            </div>
            <div class="attendance-actions"><a class="btn btn-outline" href="dashboard.php">Back</a></div>
        </header>

        <main>
            <div style="background: linear-gradient(90deg, rgba(37, 99, 235, 0.08), rgba(6, 182, 212, 0.05)); padding: 14px 16px; border-radius: 10px; margin-bottom: 16px; border-left: 4px solid #2563eb;">
                <div class="small" style="color: #6b7280; margin-bottom: 4px;">📅 Attendance Date</div>
                <div style="font-size: 1.2rem; font-weight: 600; color: #0f172a;"><?php echo date('l, d F Y', strtotime($filter_date)); ?></div>
            </div>

            <form method="get" class="attendance-filter" style="margin-bottom:12px;">
                <div class="search"><label class="small">Select Date: <input type="date" name="date"
                            value="<?php echo htmlspecialchars($filter_date); ?>"></label></div>
                <div class="attendance-actions"><button class="btn btn-outline" type="submit">Filter</button></div>
            </form>

            <?php if (empty($grouped)): ?>
                <div class="muted">No attendance records for this date.</div>
            <?php else: ?>
                <?php foreach ($grouped as $g):
                    $ci = getCourseInfo($conn, $g['course_table'], $g['course_id']);
                    $courseLabel = ($ci['code'] ?: $g['course_table']) . ' — ' . ($ci['subject'] ?: '');
                    ?>
                    <section class="card mb-12">
                        <div class="card-header">
                            <div>
                                <div class="card-title"><?php echo htmlspecialchars($g['term']); ?></div>
                                <div class="card-sub muted"><?php echo htmlspecialchars($courseLabel); ?></div>
                            </div>
                            <div class="muted small">Students: <?php echo count($g['rows']); ?></div>
                        </div>
                        <div class="mb-8">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Exam Roll</th>
                                        <th>Student</th>
                                        <th>Status</th>
                                        <th>Submitted By</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($g['rows'] as $row):
                                        $si = getStudentInfo($conn, $row['student_table'], $row['student_id']);
                                        $canEdit = ($currentRole === 'admin' || intval($row['submitted_by']) === intval($currentUserId)); ?>
                                        <tr>
                                            <td data-label="Exam Roll"><?php echo htmlspecialchars($si['exam_roll_no']); ?></td>
                                            <td data-label="Student" class="truncate"><?php echo htmlspecialchars($si['name']); ?>
                                            </td>
                                            <td data-label="Status">
                                                <?php if ($canEdit): ?>
                                                    <select class="attendance-editable" data-id="<?php echo intval($row['id']); ?>" data-prev="<?php echo htmlspecialchars($row['status']); ?>">
                                                        <option value="present" <?php if ($row['status'] == 'present')
                                                            echo 'selected'; ?>>Present</option>
                                                        <option value="absent" <?php if ($row['status'] == 'absent')
                                                            echo 'selected'; ?>>
                                                            Absent</option>
                                                        <option value="leave" <?php if ($row['status'] == 'leave')
                                                            echo 'selected'; ?>>
                                                            Leave</option>
                                                    </select>
                                                <?php else: ?>
                                                    <span
                                                        class="small status-pill status-<?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Submitted By">
                                                <?php echo htmlspecialchars($row['submitter'] ?? $row['submitted_by']); ?></td>
                                            <td data-label="Action">
                                                <?php if ($canEdit): ?>
                                                    <button class="btn btn-primary" type="button"
                                                        onclick="updateRow(<?php echo intval($row['id']); ?>)">Save</button>
                                                <?php else: ?>
                                                    <span class="muted small">Read-only</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>
<?php
// Admin-only: Read-only overall attendance across BCA & MCA
include_once __DIR__ . '/require_role.php';
require_roles(['admin']);
include __DIR__ . '/../db/connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Filters
$term = trim($_GET['term'] ?? '');
$course_filter = trim($_GET['course'] ?? ''); // format: course_table::course_id
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');

// sanitize date defaults
if ($date_from === '') $date_from = date('Y-m-01');
if ($date_to === '') $date_to = date('Y-m-d');

// Build main aggregated query
$conds = [];
$params = [];
$types = '';

$conds[] = 'att_date BETWEEN ? AND ?'; $params[] = $date_from; $params[] = $date_to; $types .= 'ss';
if ($term !== '') { $conds[] = 'term = ?'; $params[] = $term; $types .= 's'; }
if ($course_filter !== '') {
    $parts = explode('::', $course_filter);
    if (count($parts) === 2) {
        $course_table = preg_replace('/[^a-zA-Z0-9_]/','', $parts[0]);
        $course_id = intval($parts[1]);
        $conds[] = 'course_table = ? AND course_id = ?'; $params[] = $course_table; $params[] = $course_id; $types .= 'si';
    }
}

$where = '1=1';
if (!empty($conds)) $where = implode(' AND ', $conds);

$sql = "SELECT term, course_table, course_id, student_table, student_id,
    SUM(status='present') AS p,
    SUM(status='absent') AS a,
    SUM(status='leave') AS l,
    COUNT(*) AS total
    FROM attendance_records WHERE $where GROUP BY term, course_table, course_id, student_table, student_id ORDER BY term, course_table, course_id, student_id";

$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
    }
    mysqli_stmt_close($stmt);
} else {
    $rows = [];
}

// Helper to get student/course display info
function getStudentInfo($conn, $student_table, $student_id) {
    $student_table = preg_replace('/[^a-zA-Z0-9_]/','', $student_table);
    $q = "SELECT name, exam_roll_no FROM `" . $student_table . "` WHERE id = ? LIMIT 1";
    $s = mysqli_prepare($conn, $q);
    if (!$s) return ['name' => '(unknown)', 'exam_roll_no' => ''];
    mysqli_stmt_bind_param($s, 'i', $student_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $name, $exam_roll);
    if (mysqli_stmt_fetch($s)) { mysqli_stmt_close($s); return ['name'=>$name,'exam_roll_no'=>$exam_roll]; }
    mysqli_stmt_close($s);
    return ['name'=>'(unknown)','exam_roll_no'=>''];
}

function getCourseInfo($conn, $course_table, $course_id) {
    $course_table = preg_replace('/[^a-zA-Z0-9_]/','', $course_table);
    $q = "SELECT course_code, subject FROM `" . $course_table . "` WHERE id = ? LIMIT 1";
    $s = mysqli_prepare($conn, $q);
    if (!$s) return ['code'=>'(unknown)','subject'=>''];
    mysqli_stmt_bind_param($s, 'i', $course_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $code, $subject);
    if (mysqli_stmt_fetch($s)) { mysqli_stmt_close($s); return ['code'=>$code,'subject'=>$subject]; }
    mysqli_stmt_close($s);
    return ['code'=>'(unknown)','subject'=>''];
}

// Fetch distinct course options for the filter select (limited list)
$coursesOpt = [];
$qc = "SELECT DISTINCT term, course_table, course_id FROM attendance_records ORDER BY term, course_table";
$rc = mysqli_query($conn, $qc);
if ($rc) {
    while ($cr = mysqli_fetch_assoc($rc)) {
        $ct = $cr['course_table']; $cid = $cr['course_id']; $termc = $cr['term'];
        $ci = getCourseInfo($conn, $ct, $cid);
        $label = ($termc ? $termc . ' - ' : '') . ($ci['code'] ? $ci['code'] . ' — ' . $ci['subject'] : $ct . ' #' . $cid);
        $coursesOpt[] = ['value' => $ct . '::' . $cid, 'label' => $label];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overall Attendance — Admin</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/internal.css">
</head>
<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title">Overall Attendance (Read-only)</h1>
                <div class="muted">Admin view across BCA &amp; MCA. No edit allowed.</div>
            </div>
            <div>
                <a class="btn btn-small btn-outline" href="dashboard.php">Back</a>
            </div>
        </header>

        <main>
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;align-items:center">
                <label>Term: <input name="term" value="<?php echo htmlspecialchars($term); ?>" placeholder="e.g. bca_i or mca_ii"></label>
                <label>Course: <select name="course"><option value="">All</option><?php foreach($coursesOpt as $opt) { $sel = ($course_filter === $opt['value'])? 'selected':''; echo '<option value="'.htmlspecialchars($opt['value']).'" '.$sel.'>'.htmlspecialchars($opt['label']).'</option>'; } ?></select></label>
                <label>From: <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"></label>
                <label>To: <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"></label>
                <button class="btn btn-primary">Filter</button>
            </form>

            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Term</th><th>Course</th><th>Student</th><th>Exam Roll</th><th>P</th><th>L</th><th>A</th><th>Total</th><th>%</th><th>Shortage</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $studentInfo = getStudentInfo($conn, $r['student_table'], $r['student_id']);
                        $courseInfo = getCourseInfo($conn, $r['course_table'], $r['course_id']);
                        $presentPoints = intval($r['p']) + 0.5 * intval($r['l']);
                        $pct = $r['total'] > 0 ? round(($presentPoints / $r['total']) * 100, 1) : 0;
                        $short = ($pct < 75 && $r['total']>0) ? 'Yes' : 'No';
                        $courseLabel = ($courseInfo['code'] ? $courseInfo['code'] . ' — ' . $courseInfo['subject'] : $r['course_table'] . ' #' . $r['course_id']);
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['term']); ?></td>
                            <td><?php echo htmlspecialchars($courseLabel); ?></td>
                            <td><?php echo htmlspecialchars($studentInfo['name']); ?></td>
                            <td><?php echo htmlspecialchars($studentInfo['exam_roll_no']); ?></td>
                            <td><?php echo intval($r['p']); ?></td>
                            <td><?php echo intval($r['l']); ?></td>
                            <td><?php echo intval($r['a']); ?></td>
                            <td><?php echo intval($r['total']); ?></td>
                            <td><?php echo htmlspecialchars($pct); ?>%</td>
                            <td><?php echo $short; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>

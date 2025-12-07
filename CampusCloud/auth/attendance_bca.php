<?php
// Attendance for BCA terms — take and review attendance per course
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator']);
include __DIR__ . '/../db/connection.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
$currentUser = $_SESSION['username'];
$currentRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'] ?? 0;

// helper: match instructor field (which may store username or numeric user_id)
function isInstructorMatch($instructorField, $currentUser, $currentUserId)
{
    if ($instructorField === $currentUser)
        return true;
    if (!empty($currentUserId) && intval($instructorField) === intval($currentUserId))
        return true;
    return false;
}

$message = '';

// Create attendance table if not exists
$create = "CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(16) NOT NULL,
    course_table VARCHAR(64) NOT NULL,
    course_id INT NOT NULL,
    student_table VARCHAR(64) NOT NULL,
    student_id INT NOT NULL,
    att_date DATE NOT NULL,
    status ENUM('present','absent','leave') NOT NULL,
    submitted_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_att (term, course_table, course_id, student_table, student_id, att_date)
);";
mysqli_query($conn, $create);

// Handle AJAX load attendance (return existing records for a date)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'load_attendance') {
    $term = preg_replace('/[^a-z0-9_]/i', '', $_POST['term'] ?? '');
    $course_table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['course_table'] ?? '');
    $course_id = intval($_POST['course_id'] ?? 0);
    $student_table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['student_table'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');

    $res = [];
    $q = "SELECT student_id, status FROM attendance_records WHERE term=? AND course_table=? AND course_id=? AND student_table=? AND att_date=?";
    $stmt = mysqli_prepare($conn, $q);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ssiss', $term, $course_table, $course_id, $student_table, $date);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $sid, $st);
        while (mysqli_stmt_fetch($stmt)) {
            $res[intval($sid)] = $st;
        }
        mysqli_stmt_close($stmt);
    }
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Handle AJAX save attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    $term = preg_replace('/[^a-z0-9_]/i', '', $_POST['term'] ?? '');
    $course_table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['course_table'] ?? '');
    $course_id = intval($_POST['course_id'] ?? 0);
    $student_table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['student_table'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');
    $records_json = $_POST['records'] ?? '[]';

    // permission: only course instructor can submit attendance
    $canSubmit = false;
    $q = "SELECT instructor FROM `" . $course_table . "` WHERE id = ? LIMIT 1";
    $s = mysqli_prepare($conn, $q);
    if ($s) {
        mysqli_stmt_bind_param($s, 'i', $course_id);
        mysqli_stmt_execute($s);
        mysqli_stmt_bind_result($s, $instructor);
        if (mysqli_stmt_fetch($s)) {
            if (isInstructorMatch($instructor, $currentUser, $currentUserId))
                $canSubmit = true;
        }
        mysqli_stmt_close($s);
    }

    if (!$canSubmit) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not permitted to submit attendance for this course.']);
        exit;
    }

    $records = json_decode($records_json, true);
    if (!is_array($records))
        $records = [];

    // insert or update for each student
    $ins = mysqli_prepare($conn, "INSERT INTO attendance_records (term, course_table, course_id, student_table, student_id, att_date, status, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status), submitted_by = VALUES(submitted_by), created_at = CURRENT_TIMESTAMP");
    if (!$ins) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'DB prepare failed: ' . mysqli_error($conn)]);
        exit;
    }
    foreach ($records as $student_id => $status) {
        $sid = intval($student_id);
        $st = in_array($status, ['present', 'absent', 'leave']) ? $status : 'absent';
        mysqli_stmt_bind_param($ins, 'ssissssi', $term, $course_table, $course_id, $student_table, $sid, $date, $st, $currentUserId);
        mysqli_stmt_execute($ins);
    }
    mysqli_stmt_close($ins);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Attendance saved']);
    exit;
}

// Utility functions
function getCoursesForTerm($conn, $tableName, $role, $currentUser, $currentUserId)
{
    if ($role === 'admin') {
        $q = "SELECT id, course_code, subject, instructor FROM `" . $tableName . "` ORDER BY course_code";
        return mysqli_query($conn, $q);
    } else {
        // match instructor stored either as username or numeric user_id
        $q = "SELECT id, course_code, subject, instructor FROM `" . $tableName . "` WHERE instructor = ? OR instructor = ? ORDER BY course_code";
        $stmt = mysqli_prepare($conn, $q);
        if (!$stmt)
            return false;
        $uidStr = strval($currentUserId);
        mysqli_stmt_bind_param($stmt, 'ss', $currentUser, $uidStr);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $res;
    }
}

function getStudentsForTerm($conn, $studentTable)
{
    $q = "SELECT id, exam_roll_no, roll_no, name FROM `" . $studentTable . "` ORDER BY exam_roll_no";
    return mysqli_query($conn, $q);
}

function fetchAttendanceForCourse($conn, $term, $course_table, $course_id, $student_table)
{
    $q = "SELECT att_date, student_id, status FROM attendance_records WHERE term = ? AND course_table = ? AND course_id = ? AND student_table = ? ORDER BY att_date DESC";
    $s = mysqli_prepare($conn, $q);
    if (!$s)
        return [];
    mysqli_stmt_bind_param($s, 'ssis', $term, $course_table, $course_id, $student_table);
    mysqli_stmt_execute($s);
    $res = mysqli_stmt_get_result($s);
    $out = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $d = $r['att_date'];
        $out[$d][$r['student_id']] = $r['status'];
    }
    mysqli_stmt_close($s);
    return $out;
}

$courseTerms = [
    'bca_i' => 'BCA 1st Semester',
    'bca_ii' => 'BCA 2nd Semester',
    'bca_iii' => 'BCA 3rd Semester',
    'bca_iv' => 'BCA 4th Semester',
    'bca_v' => 'BCA 5th Semester',
    'bca_vi' => 'BCA 6th Semester'
];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Attendance — BCA</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/internal.css">
    <style>
        /* Simple modal */
        .att-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(0, 0, 0, 0.4);
            z-index: 50
        }

        .att-modal .panel {
            width: 760px;
            max-width: 96%;
            background: var(--surface);
            padding: 16px;
            border-radius: 10px;
        }

        .att-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            margin-top: 12px
        }

        .status-pill {
            display: inline-block;
            padding: 6px 8px;
            border-radius: 999px;
            font-weight: 700
        }
    </style>
    <script defer src="styles/theme.js"></script>
    <script>
        // helper to format date
        function todayISO() { const d = new Date(); return d.toISOString().slice(0, 10); }
    </script>
</head>

<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title">Attendance — BCA</h1>
                <div class="muted">Take attendance for courses you instruct. Admin can view all.</div>
            </div>
            <div>
                <a class="btn btn-small btn-outline" href="dashboard.php">Back</a>
                <a class="btn btn-small btn-primary" href="attendance_overall.php">Overall (Admin)</a>
            </div>
        </header>

        <main>
            <?php foreach ($courseTerms as $tableName => $label): ?>
                <section class="section-card card">
                    <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;">
                        <h2 style="margin:0"><?php echo htmlspecialchars($label); ?></h2>
                    </div>
                    <?php
                    $courses = getCoursesForTerm($conn, $tableName, $currentRole, $currentUser, $currentUserId);
                    if (!$courses || (is_object($courses) && mysqli_num_rows($courses) === 0)) {
                        echo '<p class="muted">No courses available for you in this term.</p>';
                    } else {
                        while ($course = mysqli_fetch_assoc($courses)) {
                            $courseId = $course['id'];
                            $courseCode = $course['course_code'];
                            $subject = $course['subject'];
                            $instructor = $course['instructor'];
                            $studentTable = 'bca_student_' . preg_replace('/^bca_/', '', $tableName);
                            $students = getStudentsForTerm($conn, $studentTable);
                            echo '<div class="card" style="margin:10px 0;padding:12px;">';
                            echo '<div style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0">' . htmlspecialchars($courseCode) . ' — ' . htmlspecialchars($subject) . '</h3><div class="muted">Instructor: ' . htmlspecialchars($instructor) . '</div></div>';
                            echo '<div style="margin-top:8px">';
                            $canEdit = false;
                            if (isInstructorMatch($instructor, $currentUser, $currentUserId))
                                $canEdit = true;
                            if ($canEdit) {
                                echo '<button class="btn btn-small btn-primary take-att" data-term="' . htmlspecialchars($tableName) . '" data-course_table="' . htmlspecialchars($tableName) . '" data-course_id="' . intval($courseId) . '" data-student_table="' . htmlspecialchars($studentTable) . '">Take Attendance</button>';
                            } else {
                                echo '<span class="muted">You cannot edit attendance for this course.</span>';
                            }
                            echo '</div>';

                            // Attendance summary table for this course
                            if ($students && mysqli_num_rows($students) > 0) {
                                echo '<div class="table-responsive" style="margin-top:12px"><table class="table"><thead><tr><th>Exam Roll</th><th>Roll</th><th>Name</th><th>P</th><th>L</th><th>A</th><th>%</th><th>Shortage</th></tr></thead><tbody>';
                                while ($s = mysqli_fetch_assoc($students)) {
                                    $studentId = $s['id'];
                                    $examRoll = $s['exam_roll_no'];
                                    $roll = $s['roll_no'];
                                    $name = $s['name'];
                                    // compute counts
                                    $cntQ = "SELECT SUM(status='present') as p, SUM(status='absent') as a, SUM(status='leave') as l, COUNT(*) as total FROM attendance_records WHERE term=? AND course_table=? AND course_id=? AND student_table=? AND student_id=?";
                                    $stmt = mysqli_prepare($conn, $cntQ);
                                    $pcount = $acount = $lcount = $total = 0;
                                    if ($stmt) {
                                        mysqli_stmt_bind_param($stmt, 'ssiss', $tableName, $tableName, $courseId, $studentTable, $studentId);
                                        mysqli_stmt_execute($stmt);
                                        mysqli_stmt_bind_result($stmt, $pcount, $acount, $lcount, $total);
                                        mysqli_stmt_fetch($stmt);
                                        mysqli_stmt_close($stmt);
                                    }
                                    $presentPoints = intval($pcount) + 0.5 * intval($lcount);
                                    $pct = $total > 0 ? round(($presentPoints / $total) * 100, 1) : 0;
                                    $short = ($pct < 75 && $total > 0) ? 'Yes' : 'No';
                                    echo '<tr data-student-id="' . intval($studentId) . '"><td>' . htmlspecialchars($examRoll) . '</td><td>' . htmlspecialchars($roll) . '</td><td>' . htmlspecialchars($name) . '</td><td>' . intval($pcount) . '</td><td>' . intval($lcount) . '</td><td>' . intval($acount) . '</td><td>' . htmlspecialchars($pct) . '%</td><td>' . $short . '</td></tr>';
                                }
                                echo '</tbody></table></div>';
                                // Date-wise attendance summaries for this course
                                $datesQ = "SELECT att_date, SUM(status='present') AS p, SUM(status='absent') AS a, SUM(status='leave') AS l, COUNT(*) as total FROM attendance_records WHERE term=? AND course_table=? AND course_id=? AND student_table=? GROUP BY att_date ORDER BY att_date DESC";
                                $dstmt = mysqli_prepare($conn, $datesQ);
                                if ($dstmt) {
                                    // bind: term (s), course_table (s), course_id (i), student_table (s)
                                    mysqli_stmt_bind_param($dstmt, 'ssis', $tableName, $tableName, $courseId, $studentTable);
                                    mysqli_stmt_execute($dstmt);
                                    mysqli_stmt_bind_result($dstmt, $att_date, $pcount, $acount, $lcount, $totald);
                                    $hasDates = false;
                                    $dateRows = [];
                                    while (mysqli_stmt_fetch($dstmt)) {
                                        $hasDates = true;
                                        $dateRows[] = ['date' => $att_date, 'p' => intval($pcount), 'a' => intval($acount), 'l' => intval($lcount), 'total' => intval($totald)];
                                    }
                                    mysqli_stmt_close($dstmt);
                                    if ($hasDates) {
                                        echo '<div style="margin-top:12px">';
                                        echo '<h4 style="margin:6px 0 8px 0">Previous Attendance (by date)</h4>';
                                        echo '<div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Present</th><th>Leave</th><th>Absent</th><th>Total Entries</th><th>Action</th></tr></thead><tbody>';
                                        foreach ($dateRows as $dr) {
                                            $d = htmlspecialchars($dr['date']);
                                            echo '<tr><td>' . date('d M Y', strtotime($dr['date'])) . '</td><td>' . $dr['p'] . '</td><td>' . $dr['l'] . '</td><td>' . $dr['a'] . '</td><td>' . $dr['total'] . '</td>';
                                            echo '<td><button class="btn btn-ghost view-att" data-term="' . htmlspecialchars($tableName) . '" data-course_table="' . htmlspecialchars($tableName) . '" data-course_id="' . intval($courseId) . '" data-student_table="' . htmlspecialchars($studentTable) . '" data-date="' . htmlspecialchars($dr['date']) . '">View</button></td></tr>';
                                        }
                                        echo '</tbody></table></div></div>';
                                    }
                                }
                            } else {
                                echo '<p class="muted">No students found for this course.</p>';
                            }

                            echo '</div>';
                        }
                    }
                    ?>
                </section>
            <?php endforeach; ?>
        </main>

    </div>


    <!-- Attendance modal -->
    <div class="att-modal" id="att-modal">
        <div class="panel">
            <h3 id="modal-title">Take Attendance</h3>
            <div style="margin-top:8px;">
                <label>Date: <input type="date" id="att-date" value="<?php echo date('Y-m-d'); ?>"
                        onchange="reloadAttendanceForDate()"></label>
            </div>
            <div id="att-list" style="max-height:360px;overflow:auto;margin-top:12px"></div>
            <div class="att-actions">
                <button class="btn btn-outline" id="att-cancel">Cancel</button>
                <button class="btn btn-primary" id="att-save">Save Attendance</button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('att-modal');
            const listEl = document.getElementById('att-list');
            let currentMeta = null;

            function reloadAttendanceForDate() {
                if (!currentMeta) return;
                const dateInput = document.getElementById('att-date');
                const date = dateInput.value || new Date().toISOString().slice(0, 10);

                // reload existing attendance for the new date
                try {
                    const fd2 = new FormData();
                    fd2.append('action', 'load_attendance');
                    fd2.append('term', currentMeta.term);
                    fd2.append('course_table', currentMeta.course_table);
                    fd2.append('course_id', currentMeta.course_id);
                    fd2.append('student_table', currentMeta.student_table);
                    fd2.append('date', date);
                    fetch(window.location.href, { method: 'POST', body: fd2 })
                        .then(resp => resp.json())
                        .then(existing => {
                            // update all radio buttons to match loaded data
                            Object.keys(existing).forEach(sid => {
                                const val = existing[sid];
                                const radio = listEl.querySelector(`input[name=att_${sid}][value="${val}"]`);
                                if (radio) radio.checked = true;
                            });
                            // reset any not in the loaded data
                            listEl.querySelectorAll('input[type=radio]').forEach(r => {
                                const name = r.name; // e.g., att_123
                                const sid = name.replace('att_', '');
                                if (!existing.hasOwnProperty(sid)) {
                                    // reset to default (present)
                                    const defaultRadio = listEl.querySelector(`input[name=${name}][value="present"]`);
                                    if (defaultRadio) defaultRadio.checked = true;
                                }
                            });
                        });
                } catch (e) { }
            }

            // expose to global scope so onchange handler can call it
            window.reloadAttendanceForDate = reloadAttendanceForDate;

            document.querySelectorAll('.take-att').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const term = btn.dataset.term; const course_table = btn.dataset.course_table; const course_id = btn.dataset.course_id; const student_table = btn.dataset.student_table;
                    currentMeta = { term, course_table, course_id, student_table };
                    // build list from the course card nearby
                    const card = btn.closest('.card');
                    const rows = card.querySelectorAll('tbody tr');
                    listEl.innerHTML = '';
                    rows.forEach(r => {
                        const cols = r.querySelectorAll('td');
                        if (!cols || cols.length < 3) return;
                        const exam = cols[0].textContent.trim();
                        const roll = cols[1].textContent.trim();
                        const name = cols[2].textContent.trim();
                        let studentId = r.getAttribute('data-student-id');
                        if (!studentId) {
                            const inp = r.querySelector('.proposed-input');
                            if (inp) studentId = inp.dataset.student_id;
                        }
                        if (!studentId) return;
                        const id = studentId;
                        const node = document.createElement('div');
                        node.style.display = 'flex'; node.style.justifyContent = 'space-between'; node.style.alignItems = 'center'; node.style.padding = '8px 6px';
                        node.innerHTML = `<div style="flex:1">${exam} — ${name} <div class="muted" style="font-size:0.9em">Roll: ${roll}</div></div>
                        <div style="flex:0 0 260px;display:flex;gap:8px;align-items:center;justify-content:flex-end">
                        <label><input type="radio" name="att_${id}" value="present" checked> Present</label>
                        <label><input type="radio" name="att_${id}" value="absent"> Absent</label>
                        <label><input type="radio" name="att_${id}" value="leave"> Leave</label>
                        </div>`;
                        node.setAttribute('data-student-id', id);
                        listEl.appendChild(node);
                    });
                    // set modal date default
                    const dateInput = document.getElementById('att-date');
                    if (!dateInput.value) dateInput.value = new Date().toISOString().slice(0, 10);

                    // attempt to load existing attendance for this date and prefill
                    try {
                        const fd2 = new FormData();
                        fd2.append('action', 'load_attendance');
                        fd2.append('term', term);
                        fd2.append('course_table', course_table);
                        fd2.append('course_id', course_id);
                        fd2.append('student_table', student_table);
                        fd2.append('date', dateInput.value);
                        const resp = await fetch(window.location.href, { method: 'POST', body: fd2 });
                        const existing = await resp.json();
                        Object.keys(existing).forEach(sid => {
                            const val = existing[sid];
                            const radio = listEl.querySelector(`input[name=att_${sid}][value="${val}"]`);
                            if (radio) radio.checked = true;
                        });
                    } catch (e) { }

                    modal.style.display = 'flex';
                });
            });

            // handle view by date buttons (open modal and load that date)
            document.querySelectorAll('.view-att').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const term = btn.dataset.term; const course_table = btn.dataset.course_table; const course_id = btn.dataset.course_id; const student_table = btn.dataset.student_table; const date = btn.dataset.date;
                    currentMeta = { term, course_table, course_id, student_table };
                    // build list from the course card nearby
                    const card = btn.closest('.card');
                    const rows = card.querySelectorAll('tbody tr');
                    listEl.innerHTML = '';
                    rows.forEach(r => {
                        const cols = r.querySelectorAll('td');
                        if (!cols || cols.length < 3) return;
                        const exam = cols[0].textContent.trim();
                        const roll = cols[1].textContent.trim();
                        const name = cols[2].textContent.trim();
                        let studentId = r.getAttribute('data-student-id');
                        if (!studentId) {
                            const inp = r.querySelector('.proposed-input');
                            if (inp) studentId = inp.dataset.student_id;
                        }
                        if (!studentId) return;
                        const id = studentId;
                        const node = document.createElement('div');
                        node.style.display = 'flex'; node.style.justifyContent = 'space-between'; node.style.alignItems = 'center'; node.style.padding = '8px 6px';
                        node.innerHTML = `<div style="flex:1">${exam} — ${name} <div class="muted" style="font-size:0.9em">Roll: ${roll}</div></div>
                        <div style="flex:0 0 260px;display:flex;gap:8px;align-items:center;justify-content:flex-end">
                        <label><input type="radio" name="att_${id}" value="present"> Present</label>
                        <label><input type="radio" name="att_${id}" value="absent"> Absent</label>
                        <label><input type="radio" name="att_${id}" value="leave"> Leave</label>
                        </div>`;
                        node.setAttribute('data-student-id', id);
                        listEl.appendChild(node);
                    });
                    // set modal date and load attendance for that date
                    const dateInput = document.getElementById('att-date');
                    dateInput.value = date || new Date().toISOString().slice(0, 10);
                    await reloadAttendanceForDate();
                    modal.style.display = 'flex';
                });
            });

            document.getElementById('att-cancel').addEventListener('click', () => { document.getElementById('att-date').value = ''; modal.style.display = 'none'; });
            document.getElementById('att-save').addEventListener('click', async () => {
                const date = document.getElementById('att-date').value || new Date().toISOString().slice(0, 10);
                const nodes = Array.from(listEl.querySelectorAll('[data-student-id]'));
                const records = {};
                nodes.forEach(n => {
                    const sid = n.getAttribute('data-student-id');
                    const sel = n.querySelector('input[type=radio]:checked');
                    const status = sel ? sel.value : 'absent';
                    records[sid] = status;
                });
                // post to server
                const fd = new FormData();
                fd.append('action', 'save_attendance');
                fd.append('term', currentMeta.term);
                fd.append('course_table', currentMeta.course_table);
                fd.append('course_id', currentMeta.course_id);
                fd.append('student_table', currentMeta.student_table);
                fd.append('date', date);
                fd.append('records', JSON.stringify(records));
                const res = await fetch(window.location.href, { method: 'POST', body: fd });
                const j = await res.json();
                if (j.error) { alert('Error: ' + j.error); return; }
                alert('Attendance saved for ' + date);
                modal.style.display = 'none';
                location.reload();
            });
        })();
    </script>
</body>

</html>
<?php
// Page: internal_bca.php
// Purpose: Allow moderators to add internal marks for courses they instruct (BCA).
// Admins can view/edit all marks.

include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator']);
include __DIR__ . '/../db/connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$currentUser = $_SESSION['username'];
$currentRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'] ?? 0;

function isInstructorMatch($instructorField, $currentUser, $currentUserId) {
    if ($instructorField === $currentUser) return true;
    if (!empty($currentUserId) && intval($instructorField) === intval($currentUserId)) return true;
    return false;
}

$message = '';
$messageType = 'success';

// Ensure internal marks table exists
$create = "CREATE TABLE IF NOT EXISTS internal_marks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(16) NOT NULL,
    course_table VARCHAR(64) NOT NULL,
    course_id INT NOT NULL,
    student_table VARCHAR(64) NOT NULL,
    student_id INT NOT NULL,
    marks INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mark (term, course_table, course_id, student_table, student_id)
);";
mysqli_query($conn, $create);

// Ensure requests table exists (proposed marks pending admin approval)
$createReq = "CREATE TABLE IF NOT EXISTS internal_mark_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term VARCHAR(16) NOT NULL,
    course_table VARCHAR(64) NOT NULL,
    course_id INT NOT NULL,
    student_table VARCHAR(64) NOT NULL,
    student_id INT NOT NULL,
    proposed_marks INT NOT NULL,
    submitted_by INT NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_request (term, course_table, course_id, student_table, student_id, status)
);";
mysqli_query($conn, $createReq);

// Handle AJAX/form submission to propose a mark update (creates a pending request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'propose_mark') {
    $term = preg_replace('/[^a-z0-9_]/i', '', $_POST['term'] ?? '');
    $course_table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['course_table'] ?? '');
    $course_id = intval($_POST['course_id'] ?? 0);
    $student_table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['student_table'] ?? '');
    $student_id = intval($_POST['student_id'] ?? 0);
    $proposed = intval($_POST['proposed_marks'] ?? 0);

    if (!$term || !$course_table || $course_id <= 0 || !$student_table || $student_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid parameters.']);
        exit;
    }

    // Check permission: admin can propose (and approve); moderator only for courses where they're instructor
    $allowed = false;
    if ($currentRole === 'admin') {
        $allowed = true;
    } else {
        $q = "SELECT instructor FROM `" . $course_table . "` WHERE id = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $q);
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $course_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $instructor);
            if (mysqli_stmt_fetch($stmt)) {
                if (isInstructorMatch($instructor, $currentUser, $currentUserId)) $allowed = true;
            }
            mysqli_stmt_close($stmt);
        }
    }

    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['error' => 'Not permitted to propose marks for this course.']);
        exit;
    }

    // Check if a pending request exists for this tuple
    $check = mysqli_prepare($conn, "SELECT id FROM internal_mark_requests WHERE term=? AND course_table=? AND course_id=? AND student_table=? AND student_id=? AND status='pending' LIMIT 1");
    if ($check) {
        mysqli_stmt_bind_param($check, 'ssiss', $term, $course_table, $course_id, $student_table, $student_id);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        $hasPending = mysqli_stmt_num_rows($check) > 0;
        mysqli_stmt_close($check);
        if ($hasPending) {
            http_response_code(409);
            echo json_encode(['error' => 'A pending request already exists for this student/course.']);
            exit;
        }
    }

    // Insert request
    $ins = mysqli_prepare($conn, "INSERT INTO internal_mark_requests (term, course_table, course_id, student_table, student_id, proposed_marks, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$ins) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . mysqli_error($conn)]);
        exit;
    }
    mysqli_stmt_bind_param($ins, 'ssissii', $term, $course_table, $course_id, $student_table, $student_id, $proposed, $currentUserId);
    if (!mysqli_stmt_execute($ins)) {
        http_response_code(500);
        echo json_encode(['error' => 'DB error: ' . mysqli_stmt_error($ins)]);
        mysqli_stmt_close($ins);
        exit;
    }
    mysqli_stmt_close($ins);

    echo json_encode(['ok' => true, 'message' => 'Proposed mark submitted for admin approval']);
    exit;
}

// Render page: list all BCA terms and courses; for moderator only their courses
$courseTerms = [
    'bca_i' => 'BCA 1st Semester',
    'bca_ii' => 'BCA 2nd Semester',
    'bca_iii' => 'BCA 3rd Semester',
    'bca_iv' => 'BCA 4th Semester',
    'bca_v' => 'BCA 5th Semester',
    'bca_vi' => 'BCA 6th Semester'
];

function getCoursesForTerm($conn, $tableName, $role, $currentUser, $currentUserId) {
    if ($role === 'admin') {
        $q = "SELECT id, course_code, subject, instructor FROM `" . $tableName . "` ORDER BY course_code";
        return mysqli_query($conn, $q);
    } else {
        // match instructor stored either as username or numeric user_id
        $q = "SELECT id, course_code, subject, instructor FROM `" . $tableName . "` WHERE instructor = ? OR instructor = ? ORDER BY course_code";
        $stmt = mysqli_prepare($conn, $q);
        if (!$stmt) return false;
        $uidStr = strval($currentUserId);
        mysqli_stmt_bind_param($stmt, 'ss', $currentUser, $uidStr);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);
        return $res;
    }
}

function getStudentsForTerm($conn, $studentTable) {
    $q = "SELECT id, exam_roll_no, roll_no, name FROM `" . $studentTable . "` ORDER BY exam_roll_no";
    return mysqli_query($conn, $q);
}

function getExistingMark($conn, $term, $course_table, $course_id, $student_table, $student_id) {
    $q = "SELECT marks FROM internal_marks WHERE term = ? AND course_table = ? AND course_id = ? AND student_table = ? AND student_id = ? LIMIT 1";
    $s = mysqli_prepare($conn, $q);
    if (!$s) return null;
    mysqli_stmt_bind_param($s, 'ssiss', $term, $course_table, $course_id, $student_table, $student_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $marks);
    if (mysqli_stmt_fetch($s)) {
        mysqli_stmt_close($s);
        return $marks;
    }
    mysqli_stmt_close($s);
    return null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Internal Marks — BCA</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/internal.css">
    <script defer src="styles/theme.js"></script>
</head>
<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title">Internal Marks — BCA</h1>
                <div class="muted">Moderator: add marks for your courses. Admin: view/edit all.</div>
            </div>
            <div>
                <a class="btn btn-small btn-outline" href="dashboard.php">Back</a>
                <a class="btn btn-small btn-primary" href="logout.php">Logout</a>
            </div>
        </header>
        <div class="internal-search">
            <input type="search" id="internal-search" placeholder="Search students, roll, exam roll or course..." aria-label="Search internal marks">
            <button type="button" class="clear-search" id="clear-internal-search" title="Clear search">✕</button>
            <div class="search-hint muted">Type to filter rows on this page</div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>" style="margin:15px 0;"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <main>
            <?php foreach ($courseTerms as $tableName => $label): ?>
                <section class="section-card card" style="margin-bottom:18px;">
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
                                echo '<div class="card" style="margin:10px 0;padding:10px;">';
                                echo '<h3 style="margin:0 0 8px;">' . htmlspecialchars($courseCode) . ' — ' . htmlspecialchars($subject) . ' <span class="muted" style="font-size:0.9em">(Instructor: ' . htmlspecialchars($instructor) . ')</span></h3>';
                                // students table name convention
                                $studentTable = 'bca_student_' . preg_replace('/^bca_/', '', $tableName);
                                $students = getStudentsForTerm($conn, $studentTable);
                                if (!$students || mysqli_num_rows($students) === 0) {
                                    echo '<p class="muted">No students found for this term.</p>';
                                } else {
                                    echo '<div class="table-responsive"><table class="table"><thead><tr><th>Exam Roll</th><th>Roll</th><th>Name</th><th>Internal Marks</th><th>Action</th></tr></thead><tbody>';
                                    while ($s = mysqli_fetch_assoc($students)) {
                                        $studentId = $s['id'];
                                        $examRoll = $s['exam_roll_no'];
                                        $roll = $s['roll_no'];
                                        $name = $s['name'];
                                        $existing = getExistingMark($conn, $tableName, $tableName, $courseId, $studentTable, $studentId);
                                        $val = ($existing !== null) ? intval($existing) : '';
                                        echo '<tr class="mark-row">';
                                        echo '<td>' . htmlspecialchars($examRoll) . '</td>';
                                        echo '<td>' . htmlspecialchars($roll) . '</td>';
                                        echo '<td>' . htmlspecialchars($name) . '</td>';
                                        // Approved marks (read-only)
                                        $approvedDisplay = ($existing !== null) ? intval($existing) : '';
                                        echo '<td class="approved-marks" style="width:90px;">' . htmlspecialchars($approvedDisplay) . '</td>';
                                        // Proposed input (separate)
                                        echo '<td><input type="number" min="0" max="100" placeholder="Enter marks (0-100)" class="proposed-input" data-original="" data-term="' . htmlspecialchars($tableName) . '" data-course_table="' . htmlspecialchars($tableName) . '" data-course_id="' . intval($courseId) . '" data-student_table="' . htmlspecialchars($studentTable) . '" data-student_id="' . intval($studentId) . '" value="" style="width:90px;"></td>';
                                        // Propose button and pending indicator
                                        // Check if there's a pending request
                                        $checkReq = $conn->prepare("SELECT id FROM internal_mark_requests WHERE term=? AND course_table=? AND course_id=? AND student_table=? AND student_id=? AND status='pending' LIMIT 1");
                                        $hasPending = false;
                                        if ($checkReq) {
                                            $tn = $tableName; $ct = $tableName; $ci = $courseId; $st = $studentTable; $sid = $studentId;
                                            $checkReq->bind_param('ssiss', $tn, $ct, $ci, $st, $sid);
                                            $checkReq->execute();
                                            $checkReq->store_result();
                                            $hasPending = $checkReq->num_rows > 0;
                                            $checkReq->close();
                                        }
                                        $disabled = $hasPending ? 'disabled' : '';
                                        $pendingHtml = $hasPending ? '<span class="muted">(Pending)</span>' : '';
                                        echo '<td><button class="btn btn-small btn-primary propose-mark" ' . $disabled . '>Propose</button> ' . $pendingHtml . '</td>';
                                        echo '</tr>';
                                    }
                                    echo '</tbody></table></div>';
                                }
                                echo '</div>';
                            }
                        }
                    ?>
                </section>
            <?php endforeach; ?>
        </main>
    </div>

    <script>
        // Search/filter rows across all course tables on the page
        (function() {
            const searchInput = document.getElementById('internal-search');
            const clearBtn = document.getElementById('clear-internal-search');
            if (!searchInput) return;
            function normalize(s){ return (s||'').toString().toLowerCase(); }
            function applyFilter(q) {
                document.querySelectorAll('.mark-row').forEach(row => {
                    const rowText = normalize(row.textContent || '');
                    const card = row.closest('.card');
                    const courseHeader = card ? normalize(card.querySelector('h3')?.textContent || '') : '';
                    const visible = q === '' || rowText.indexOf(q) !== -1 || courseHeader.indexOf(q) !== -1;
                    row.style.display = visible ? '' : 'none';
                    if (visible && q !== '') {
                        row.classList.add('highlight');
                        setTimeout(()=>row.classList.remove('highlight'), 800);
                    }
                });
            }

            searchInput.addEventListener('input', (e) => {
                const q = normalize(e.target.value.trim());
                applyFilter(q);
                if (clearBtn) clearBtn.style.display = q === '' ? 'none' : 'inline-block';
            });

            if (clearBtn) {
                clearBtn.style.display = searchInput.value.trim() === '' ? 'none' : 'inline-block';
                clearBtn.addEventListener('click', () => {
                    searchInput.value = '';
                    applyFilter('');
                    clearBtn.style.display = 'none';
                    searchInput.focus();
                });
            }
        })();
        // Helpers
        function showStatus(el, type, msg) {
            el.classList.remove('status-saving','status-ok','status-error');
            if (type === 'saving') el.classList.add('status-saving');
            if (type === 'ok') el.classList.add('status-ok');
            if (type === 'error') el.classList.add('status-error');
            if (msg) el.setAttribute('title', msg);
        }

        // Enable propose when proposed input changed
        document.querySelectorAll('.proposed-input').forEach(input => {
            const btn = input.closest('tr').querySelector('.propose-mark');
            input.addEventListener('input', () => {
                const cur = input.value === '' ? '' : String(parseInt(input.value,10));
                // enable only if a numeric value entered
                btn.disabled = (cur === '');
                const status = input.closest('tr').querySelector('.save-status');
                if (status) status.className = 'save-status';
            });

            // Enter to propose
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const btn = input.closest('tr').querySelector('.propose-mark');
                    if (!btn.disabled) btn.click();
                }
            });
        });

        // Propose handler with inline feedback (creates a pending request)
        async function proposeRow(button) {
            const row = button.closest('tr');
            const input = row.querySelector('.proposed-input');
            const statusEl = row.querySelector('.save-status');
            const term = input.dataset.term;
            const course_table = input.dataset.course_table;
            const course_id = input.dataset.course_id;
            const student_table = input.dataset.student_table;
            const student_id = input.dataset.student_id;
            const proposed = input.value;

            // UI: show saving
            button.disabled = true;
            if (statusEl) showStatus(statusEl, 'saving');

            const fd = new FormData();
            fd.append('action', 'propose_mark');
            fd.append('term', term);
            fd.append('course_table', course_table);
            fd.append('course_id', course_id);
            fd.append('student_table', student_table);
            fd.append('student_id', student_id);
            fd.append('proposed_marks', proposed);

            try {
                const res = await fetch(window.location.href, { method: 'POST', body: fd });
                const j = await res.json();
                if (j.error) {
                    if (statusEl) showStatus(statusEl, 'error', j.error);
                    button.disabled = false;
                    alert('Error: ' + j.error);
                    return;
                }
                // success — mark row as pending
                if (statusEl) showStatus(statusEl, 'ok', 'Requested');
                // show pending text in action cell
                const actionCell = button.closest('td');
                if (actionCell) actionCell.innerHTML = '<span class="muted">(Pending)</span>';
            } catch (err) {
                if (statusEl) showStatus(statusEl, 'error', err.message || String(err));
                alert('Network error: ' + err);
                button.disabled = false;
            }
        }

        document.querySelectorAll('.propose-mark').forEach(btn => {
            btn.addEventListener('click', (e) => {
                if (btn.disabled) return;
                proposeRow(btn);
            });
        });

        // Collapsible course cards
        document.querySelectorAll('.section-card .card').forEach(card => {
            const header = card.querySelector('h3');
            if (!header) return;
            // make header clickable to toggle
            header.style.cursor = 'pointer';
            const body = card.querySelector('.table-responsive');
            header.addEventListener('click', () => {
                if (!body) return;
                body.style.display = body.style.display === 'none' ? '' : 'none';
            });
        });

    </script>
</body>
</html>
<?php
include_once __DIR__ . '/require_role.php';
// Allow admin, moderator, faculty and users to view internal marks
require_roles(['admin','moderator','faculty','user']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Internal Marks — BCA</title>
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
    <h2>BCA Internal Marks</h2>
    
</main>
</body>
</html>

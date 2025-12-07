<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator', 'user']);
include __DIR__ . '/../db/connection.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $term = trim($_POST['term'] ?? '');
    $student_id = intval($_POST['student_id'] ?? 0);
    $exam_roll_no = trim($_POST['exam_roll_no'] ?? '');
    $roll_no = trim($_POST['roll_no'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $father = trim($_POST['father'] ?? '');
    $mother = trim($_POST['mother'] ?? '');
    $phone_no = trim($_POST['phone_no'] ?? '');
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    
    if ($action === 'add' || $action === 'edit' || $action === 'delete') {
        $errors = [];
        if (empty($term)) $errors[] = 'Semester';
        if ($action !== 'delete' && empty($exam_roll_no)) $errors[] = 'Exam Roll No';
        if ($action !== 'delete' && empty($roll_no)) $errors[] = 'Roll No';
        if ($action !== 'delete' && empty($name)) $errors[] = 'Name';
        if ($action !== 'delete' && empty($father)) $errors[] = 'Father';
        if ($action !== 'delete' && empty($mother)) $errors[] = 'Mother';
        if ($action !== 'delete' && empty($phone_no)) $errors[] = 'Phone';
        if ($action !== 'delete' && empty($parent_phone)) $errors[] = 'Parent Phone';
        if ($action !== 'add' && $student_id <= 0) $errors[] = 'Invalid student';
        
        if (!empty($errors)) {
            $message = "Missing: " . implode(', ', $errors);
            $messageType = "error";
        } else {
            $tableName = "mca_student_" . preg_replace('/[^a-z]/', '', $term);
            $userId = $_SESSION['user_id'];
            
            if ($action === 'add') {
                // Check if exam_roll_no already exists in actual table
                $checkTable = $conn->prepare("SELECT id FROM `$tableName` WHERE exam_roll_no = ? LIMIT 1");
                $duplicateInTable = false;
                if ($checkTable) {
                    $checkTable->bind_param('s', $exam_roll_no);
                    $checkTable->execute();
                    $checkTable->store_result();
                    $duplicateInTable = ($checkTable->num_rows > 0);
                    $checkTable->close();
                }
                
                if ($duplicateInTable) {
                    $message = "Exam Roll No already exists in this semester. Please use a unique exam roll no.";
                    $messageType = "error";
                } else {
                    // Check if a pending task with same exam_roll_no exists
                    $checkPending = $conn->prepare("SELECT st.id FROM student_tasks st JOIN student_task_details std ON st.id = std.task_id WHERE st.table_name = ? AND std.exam_roll_no = ? AND st.status = 'pending' LIMIT 1");
                    $duplicatePending = false;
                    if ($checkPending) {
                        $checkPending->bind_param('ss', $tableName, $exam_roll_no);
                        $checkPending->execute();
                        $checkPending->store_result();
                        $duplicatePending = ($checkPending->num_rows > 0);
                        $checkPending->close();
                    }
                    
                    if ($duplicatePending) {
                        $message = "A pending request with this exam roll no already exists. Please wait for admin review.";
                        $messageType = "error";
                    } else {
                        // Continue with add logic
                // Remove previous non-pending tasks for same exam_roll_no to avoid duplicates
                $cleanup = $conn->prepare("DELETE st FROM student_tasks st JOIN student_task_details std ON st.id = std.task_id WHERE st.table_name = ? AND std.exam_roll_no = ? AND st.status != 'pending'");
                if ($cleanup) {
                    $cleanup->bind_param('ss', $tableName, $exam_roll_no);
                    $cleanup->execute();
                    $cleanup->close();
                }
                // Insert a new add task (allow multiple add requests by the same user)
                $task = $conn->prepare("INSERT INTO student_tasks (task_type, table_name, submitted_by, status) VALUES (?, ?, ?, 'pending')");
                $task->bind_param('ssi', $action, $tableName, $userId);
                if ($task->execute()) {
                    $taskId = $task->insert_id;
                    $detail = $conn->prepare("INSERT INTO student_task_details (task_id, exam_roll_no, roll_no, name, father, mother, phone_no, parent_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $detail->bind_param('isssssss', $taskId, $exam_roll_no, $roll_no, $name, $father, $mother, $phone_no, $parent_phone);
                    if ($detail->execute()) {
                        $message = "✓ Student added! Waiting for admin approval.";
                        $messageType = "success";
                    } else {
                        $message = "Error: " . $detail->error;
                        $messageType = "error";
                    }
                    $detail->close();
                } else {
                    $message = "Error: " . $task->error;
                    $messageType = "error";
                }
                $task->close();
                    }
                }
            } elseif ($action === 'edit') {
                // Remove previous non-pending edit tasks for same student to avoid duplicates
                $cleanup = $conn->prepare("DELETE FROM student_tasks WHERE table_name = ? AND student_id = ? AND status != 'pending'");
                if ($cleanup) {
                    $cleanup->bind_param('si', $tableName, $student_id);
                    $cleanup->execute();
                    $cleanup->close();
                }
                $check = $conn->prepare("SELECT id FROM student_tasks WHERE task_type='edit' AND student_id=? AND submitted_by=? AND status='pending'");
                $check->bind_param('ii', $student_id, $userId);
                $check->execute();
                $dupResult = $check->get_result();
                if ($dupResult->num_rows > 0) {
                    $message = "⚠️ Pending edit exists. Wait for approval.";
                    $messageType = "error";
                } else {
                    $task = $conn->prepare("INSERT INTO student_tasks (task_type, table_name, student_id, submitted_by, status) VALUES (?, ?, ?, ?, 'pending')");
                    $task->bind_param('ssii', $action, $tableName, $student_id, $userId);
                    if ($task->execute()) {
                        $taskId = $task->insert_id;
                        $detail = $conn->prepare("INSERT INTO student_task_details (task_id, exam_roll_no, roll_no, name, father, mother, phone_no, parent_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $detail->bind_param('isssssss', $taskId, $exam_roll_no, $roll_no, $name, $father, $mother, $phone_no, $parent_phone);
                        if ($detail->execute()) {
                            $message = "✓ Update submitted! Waiting for admin approval.";
                            $messageType = "success";
                        } else {
                            $message = "Error: " . $detail->error;
                            $messageType = "error";
                        }
                        $detail->close();
                    } else {
                        $message = "Error: " . $task->error;
                        $messageType = "error";
                    }
                    $task->close();
                }
                $check->close();
            } elseif ($action === 'delete') {
                // Fetch student data first
                $fetchStmt = $conn->prepare("SELECT exam_roll_no, roll_no, name, father, mother, phone_no, parent_phone FROM `$tableName` WHERE id = ?");
                $fetchStmt->bind_param('i', $student_id);
                $fetchStmt->execute();
                $result = $fetchStmt->get_result();
                $student = $result->fetch_assoc();
                $fetchStmt->close();
                
                // Check if a pending delete task already exists for this student
                $checkDelete = $conn->prepare("SELECT id FROM student_tasks WHERE table_name = ? AND student_id = ? AND task_type = 'delete' AND status = 'pending'");
                $checkDelete->bind_param('si', $tableName, $student_id);
                $checkDelete->execute();
                $deleteCheck = $checkDelete->get_result();
                
                if ($deleteCheck->num_rows > 0) {
                    $message = "A delete request for this student is already pending approval.";
                    $messageType = "error";
                    $deleteCheck->free();
                    $checkDelete->close();
                } else {
                    $checkDelete->close();
                    if (!$student) {
                        $message = "Student not found.";
                        $messageType = "error";
                    } else {
                        // Remove previous non-pending delete tasks for same student to avoid duplicates
                        $cleanup = $conn->prepare("DELETE FROM student_tasks WHERE table_name = ? AND student_id = ? AND task_type = 'delete' AND status != 'pending'");
                        if ($cleanup) {
                            $cleanup->bind_param('si', $tableName, $student_id);
                            $cleanup->execute();
                            $cleanup->close();
                        }
                        $task = $conn->prepare("INSERT INTO student_tasks (task_type, table_name, student_id, submitted_by, status) VALUES (?, ?, ?, ?, 'pending')");
                        $task->bind_param('ssii', $action, $tableName, $student_id, $userId);
                        if ($task->execute()) {
                            $task_id = $conn->insert_id;
                            // Store student details in student_task_details
                            $detail = $conn->prepare("INSERT INTO student_task_details (task_id, exam_roll_no, roll_no, name, father, mother, phone_no, parent_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            if ($detail) {
                                $detail->bind_param('isssssss', $task_id, $student['exam_roll_no'], $student['roll_no'], $student['name'], $student['father'], $student['mother'], $student['phone_no'], $student['parent_phone']);
                                $detail->execute();
                                $detail->close();
                            }
                            $message = "✓ Delete request submitted! Waiting for admin approval.";
                            $messageType = "success";
                        } else {
                            $message = "Error: " . $task->error;
                            $messageType = "error";
                        }
                        $task->close();
                    }
                }
            }
        }
    }
}

function getStudents($conn, $tableName) {
    $result = $conn->query("SELECT id, exam_roll_no, roll_no, name, father, mother, phone_no, parent_phone FROM `$tableName` ORDER BY exam_roll_no");
    return $result;
}

function renderTable($result, $tableName) {
    if (!$result || $result->num_rows === 0) {
        echo '<p class="muted">No students</p>';
        return;
    }
    echo '<div class="table-responsive"><table class="table"><thead><tr><th>Exam Roll</th><th>Roll No</th><th>Name</th><th>Father</th><th>Mother</th><th>Phone</th><th>Parent Phone</th><th>Actions</th></tr></thead><tbody>';
    while ($row = $result->fetch_assoc()) {
        echo '<tr><td>' . htmlspecialchars($row['exam_roll_no']) . '</td><td>' . htmlspecialchars($row['roll_no'] ?? '') . '</td><td>' . htmlspecialchars($row['name'] ?? '') . '</td><td>' . htmlspecialchars($row['father'] ?? '') . '</td><td>' . htmlspecialchars($row['mother'] ?? '') . '</td><td>' . htmlspecialchars($row['phone_no'] ?? '') . '</td><td>' . htmlspecialchars($row['parent_phone'] ?? '') . '</td><td><button type="button" class="btn-edit" data-id="' . intval($row['id']) . '" data-table="' . htmlspecialchars($tableName, ENT_QUOTES) . '">Edit</button> <button type="button" class="btn-delete" data-id="' . intval($row['id']) . '" data-table="' . htmlspecialchars($tableName, ENT_QUOTES) . '">Delete</button></td></tr>';
    }
    echo '</tbody></table></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MCA Students</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/students.css">
    <script defer src="styles/theme.js"></script>
</head>
<body>
    <div class="container">
        <header class="page-header">
            <div><h1>MCA Students</h1><p class="muted">Manage students</p></div>
            <div><a class="btn btn-small btn-outline" href="dashboard.php">Back</a> <a class="btn btn-small btn-primary" href="logout.php">Logout</a></div>
        </header>
        <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <main>
            <div class="management-controls">
                <input type="text" id="search" class="search-box" placeholder="🔍 Search..." onkeyup="search()">
            </div>

            <div id="form-box" class="card" style="display: none;">
                <h2 id="form-title">Add Student</h2>
                <form id="main-form" method="POST" onsubmit="return validateFormInputs();">
                    <input type="hidden" name="action" id="action" value="add">
                    <input type="hidden" name="student_id" id="student_id" value="">
                    <input type="hidden" name="term" id="term" value="">
                    <div class="form-group"><label for="exam_roll_no">Exam Roll *</label><input type="text" id="exam_roll_no" name="exam_roll_no" required></div>
                    <div class="form-group"><label for="roll_no">Roll No *</label><input type="text" id="roll_no" name="roll_no" required></div>
                    <div class="form-group"><label for="name">Name *</label><input type="text" id="name" name="name" required></div>
                    <div class="form-group"><label for="father">Father *</label><input type="text" id="father" name="father" required></div>
                    <div class="form-group"><label for="mother">Mother *</label><input type="text" id="mother" name="mother" required></div>
                    <div class="form-group"><label for="phone_no">Phone *</label><input type="tel" id="phone_no" name="phone_no" required></div>
                    <div class="form-group"><label for="parent_phone">Parent Phone *</label><input type="tel" id="parent_phone" name="parent_phone" required></div>
                    <div class="form-actions"><button type="submit" class="btn btn-success">Submit</button> <button type="button" class="btn btn-outline" onclick="toggleForm()">Cancel</button></div>
                </form>
            </div>

            <?php 
            $terms = ['i' => '1st Year', 'ii' => '2nd Year', 'iii' => '3rd Year', 'iv' => '4th Year', 'v' => '5th Year', 'vi' => '6th Year'];
            foreach ($terms as $code => $label): 
            ?>
                <section class="section-card card">
                    <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;">
                        <h2 style="margin:0"><?php echo $label; ?></h2>
                        <div>
                            <button type="button" class="btn btn-sm" onclick="openAddForTerm('<?php echo $code; ?>')">+ Add Student</button>
                        </div>
                    </div>
                    <?php renderTable(getStudents($conn, "mca_student_$code"), "mca_student_$code"); ?>
                </section>
                <hr>
            <?php endforeach; ?>
        </main>
    </div>

    <div id="modal" class="modal" style="display: none;">
        <div class="modal-content">
            <h3>Confirm Delete</h3>
            <p>Delete this student? (Admin approval required)</p>
            <div class="modal-actions">
                <button class="btn btn-danger" onclick="confirmDelete()">Yes</button>
                <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        let delId = null, delTable = null;

        function toggleForm() {
            const box = document.getElementById('form-box');
            box.style.display = box.style.display === 'none' ? 'block' : 'none';
            if (box.style.display === 'block') {
                document.getElementById('action').value = 'add';
                document.getElementById('form-title').textContent = 'Add Student';
                document.getElementById('student_id').value = '';
                document.getElementById('main-form').reset();
            }
        }

        // Open the Add form prefilled for a specific term (table)
        function openAddForTerm(term) {
            document.getElementById('action').value = 'add';
            document.getElementById('form-title').textContent = 'Add Student';
            document.getElementById('student_id').value = '';
            const termEl = document.getElementById('term');
            if (termEl) termEl.value = term;
            document.getElementById('main-form').reset();
            // Ensure the selected term remains after reset
            if (termEl) termEl.value = term;
            document.getElementById('form-box').style.display = 'block';
            // focus first input
            const first = document.getElementById('exam_roll_no');
            if (first) first.focus();
        }

        function validateFormInputs() {
            const term = document.getElementById('term').value.trim();
            const exam_roll_no = document.getElementById('exam_roll_no').value.trim();
            const roll_no = document.getElementById('roll_no').value.trim();
            const name = document.getElementById('name').value.trim();
            const father = document.getElementById('father').value.trim();
            const mother = document.getElementById('mother').value.trim();
            const phone_no = document.getElementById('phone_no').value.trim();
            const parent_phone = document.getElementById('parent_phone').value.trim();
            
            if (!term || !exam_roll_no || !roll_no || !name || !father || !mother || !phone_no || !parent_phone) {
                alert('❌ All fields required');
                return false;
            }
            return true;
        }

        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('btn-edit')) {
                const id = e.target.getAttribute('data-id');
                const table = e.target.getAttribute('data-table');
                fetch('get_student.php?id=' + id + '&table=' + encodeURIComponent(table))
                    .then(r => r.json())
                    .then(data => {
                        if (data.error) { alert(data.error); return; }
                        const sem = table.split('_').pop();
                        document.getElementById('action').value = 'edit';
                        document.getElementById('form-title').textContent = 'Edit Student';
                        document.getElementById('student_id').value = id;
                        document.getElementById('term').value = sem;
                        document.getElementById('exam_roll_no').value = data.exam_roll_no || '';
                        document.getElementById('roll_no').value = data.roll_no || '';
                        document.getElementById('name').value = data.name || '';
                        document.getElementById('father').value = data.father || '';
                        document.getElementById('mother').value = data.mother || '';
                        document.getElementById('phone_no').value = data.phone_no || '';
                        document.getElementById('parent_phone').value = data.parent_phone || '';
                        document.getElementById('form-box').style.display = 'block';
                    });
            }
            if (e.target.classList.contains('btn-delete')) {
                delId = e.target.getAttribute('data-id');
                delTable = e.target.getAttribute('data-table');
                document.getElementById('modal').style.display = 'flex';
            }
        });

        function confirmDelete() {
            const term = delTable.split('_').pop();
            const fd = new FormData();
            fd.append('action', 'delete');
            fd.append('student_id', delId);
            fd.append('term', term);
            fetch(window.location.href, { method: 'POST', body: fd })
                .then(() => { alert('✓ Submitted!'); location.reload(); })
                .catch(err => alert('Error: ' + err));
            document.getElementById('modal').style.display = 'none';
        }

        function closeModal() {
            document.getElementById('modal').style.display = 'none';
        }

        document.getElementById('modal').addEventListener('click', (e) => {
            if (e.target.id === 'modal') closeModal();
        });

        function search() {
            const q = document.getElementById('search').value.toLowerCase();
            document.querySelectorAll('.table tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        }
    </script>
</body>
</html>

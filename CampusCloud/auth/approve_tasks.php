<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin']);
include __DIR__ . '/../db/connection.php';

// Unified approval handler — supports ?type=course or ?type=student
$type = trim($_GET['type'] ?? 'course');
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_id = intval($_POST['task_id'] ?? 0);
    $action = trim($_POST['action'] ?? '');
    $admin_remarks = trim($_POST['admin_remarks'] ?? $_POST['remarks'] ?? '');

    if (!$task_id || !in_array($action, ['approve', 'reject'])) {
        die('Invalid request');
    }

    if ($type === 'student') {
        // Student task flow
        $taskQuery = "SELECT st.*, std.exam_roll_no, std.roll_no, std.name, std.father, std.mother, std.phone_no, std.parent_phone
                      FROM student_tasks st
                      LEFT JOIN student_task_details std ON st.id = std.task_id
                      WHERE st.id = ?";
        $stmt = mysqli_prepare($conn, $taskQuery);
        mysqli_stmt_bind_param($stmt, 'i', $task_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $task = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$task) {
            $message = 'Task not found';
            $messageType = 'error';
        } elseif ($action === 'approve') {
            $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $task['table_name']);

            // Verify table exists
            $tableCheckQuery = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
            $tableCheckStmt = mysqli_prepare($conn, $tableCheckQuery);
            mysqli_stmt_bind_param($tableCheckStmt, 's', $tableName);
            mysqli_stmt_execute($tableCheckStmt);
            $tableCheckRes = mysqli_stmt_get_result($tableCheckStmt);
            mysqli_stmt_close($tableCheckStmt);

            if (mysqli_num_rows($tableCheckRes) === 0) {
                $message = "Error: Table '$tableName' does not exist. Cannot process this request.";
                $messageType = 'error';
            } else {
                if ($task['task_type'] === 'add') {
                    $insertQuery = "INSERT INTO `" . $tableName . "` (exam_roll_no, roll_no, name, father, mother, phone_no, parent_phone) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $s = mysqli_prepare($conn, $insertQuery);
                    if ($s) {
                        mysqli_stmt_bind_param($s, 'sssssss', $task['exam_roll_no'], $task['roll_no'], $task['name'], $task['father'], $task['mother'], $task['phone_no'], $task['parent_phone']);
                        if (mysqli_stmt_execute($s)) {
                            $u = mysqli_prepare($conn, "UPDATE student_tasks SET status='approved', admin_remarks=?, updated_at=NOW() WHERE id=?");
                            mysqli_stmt_bind_param($u, 'si', $admin_remarks, $task_id);
                            mysqli_stmt_execute($u);
                            mysqli_stmt_close($u);

                            $message = '✓ Student added successfully! Task removed from pending.';
                            $messageType = 'success';
                        } else {
                            $message = 'Error adding student: ' . mysqli_stmt_error($s);
                            $messageType = 'error';
                        }
                        mysqli_stmt_close($s);
                    } else {
                        $message = 'Error preparing insert: ' . mysqli_error($conn);
                        $messageType = 'error';
                    }
                } elseif ($task['task_type'] === 'edit') {
                    $updateQuery = "UPDATE `" . $tableName . "` SET exam_roll_no=?, roll_no=?, name=?, father=?, mother=?, phone_no=?, parent_phone=? WHERE id=?";
                    $s = mysqli_prepare($conn, $updateQuery);
                    if ($s) {
                        mysqli_stmt_bind_param($s, 'sssssssi', $task['exam_roll_no'], $task['roll_no'], $task['name'], $task['father'], $task['mother'], $task['phone_no'], $task['parent_phone'], $task['student_id']);
                        if (mysqli_stmt_execute($s)) {
                            $u = mysqli_prepare($conn, "UPDATE student_tasks SET status='approved', admin_remarks=?, updated_at=NOW() WHERE id=?");
                            mysqli_stmt_bind_param($u, 'si', $admin_remarks, $task_id);
                            mysqli_stmt_execute($u);
                            mysqli_stmt_close($u);

                            $message = '✓ Student updated successfully! Task removed from pending.';
                            $messageType = 'success';
                        } else {
                            $message = 'Error updating student: ' . mysqli_stmt_error($s);
                            $messageType = 'error';
                        }
                        mysqli_stmt_close($s);
                    } else {
                        $message = 'Error preparing update: ' . mysqli_error($conn);
                        $messageType = 'error';
                    }
                } elseif ($task['task_type'] === 'delete') {
                    $delQuery = "DELETE FROM `" . $tableName . "` WHERE id = ?";
                    $s = mysqli_prepare($conn, $delQuery);
                    if ($s) {
                        mysqli_stmt_bind_param($s, 'i', $task['student_id']);
                        if (mysqli_stmt_execute($s)) {
                            $u = mysqli_prepare($conn, "UPDATE student_tasks SET status='approved', admin_remarks=?, updated_at=NOW() WHERE id=?");
                            mysqli_stmt_bind_param($u, 'si', $admin_remarks, $task_id);
                            mysqli_stmt_execute($u);
                            mysqli_stmt_close($u);

                            $message = '✓ Student deleted successfully! Task removed from pending.';
                            $messageType = 'success';
                        } else {
                            $message = 'Error deleting student: ' . mysqli_stmt_error($s);
                            $messageType = 'error';
                        }
                        mysqli_stmt_close($s);
                    } else {
                        $message = 'Error preparing delete: ' . mysqli_error($conn);
                        $messageType = 'error';
                    }
                }
            }
        } else {
            // reject student
            $u = mysqli_prepare($conn, "UPDATE student_tasks SET status='rejected', admin_remarks=?, updated_at=NOW() WHERE id=?");
            mysqli_stmt_bind_param($u, 'si', $admin_remarks, $task_id);
            if (mysqli_stmt_execute($u)) {
                $message = '✓ Request rejected! Task removed from pending.';
                $messageType = 'success';
            } else {
                $message = 'Error rejecting request: ' . mysqli_stmt_error($u);
                $messageType = 'error';
            }
            mysqli_stmt_close($u);
        }

    } else {
        // Course flow
        $taskQuery = "SELECT t.*, td.course_code, td.subject, td.course_type, td.credits, td.internal_marks, td.external_marks, td.instructor, td.not_in_use
                      FROM tasks t
                      LEFT JOIN task_details td ON t.id = td.task_id
                      WHERE t.id = ?";
        $stmt = mysqli_prepare($conn, $taskQuery);
        mysqli_stmt_bind_param($stmt, 'i', $task_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $task = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);

        if (!$task) {
            $message = 'Task not found';
            $messageType = 'error';
        } elseif ($action === 'approve') {
            $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $task['table_name']);
            if ($task['task_type'] === 'add') {
                $insertQuery = "INSERT INTO `" . $tableName . "` (course_code, subject, course_type, credits, internal_marks, external_marks, instructor, not_in_use) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $s = mysqli_prepare($conn, $insertQuery);
                if ($s) {
                    mysqli_stmt_bind_param($s, 'sssiiisi', $task['course_code'], $task['subject'], $task['course_type'], $task['credits'], $task['internal_marks'], $task['external_marks'], $task['instructor'], $task['not_in_use']);
                    if (mysqli_stmt_execute($s)) {
                        $u = mysqli_prepare($conn, "UPDATE tasks SET status='approved', updated_at=NOW() WHERE id=?");
                        mysqli_stmt_bind_param($u, 'i', $task_id);
                        mysqli_stmt_execute($u);
                        mysqli_stmt_close($u);

                        $message = 'Course added successfully!';
                        $messageType = 'success';
                    } else {
                        $message = 'Error adding course: ' . mysqli_stmt_error($s);
                        $messageType = 'error';
                    }
                    mysqli_stmt_close($s);
                } else {
                    $message = 'Error preparing insert: ' . mysqli_error($conn);
                    $messageType = 'error';
                }
            } elseif ($task['task_type'] === 'edit') {
                $updateCourseQuery = "UPDATE `" . $tableName . "` SET course_code=?, subject=?, course_type=?, credits=?, internal_marks=?, external_marks=?, instructor=?, not_in_use=? WHERE id=?";
                $s = mysqli_prepare($conn, $updateCourseQuery);
                if ($s) {
                    mysqli_stmt_bind_param($s, 'sssiiisii', $task['course_code'], $task['subject'], $task['course_type'], $task['credits'], $task['internal_marks'], $task['external_marks'], $task['instructor'], $task['not_in_use'], $task['course_id']);
                    if (mysqli_stmt_execute($s)) {
                        $u = mysqli_prepare($conn, "UPDATE tasks SET status='approved', updated_at=NOW() WHERE id=?");
                        mysqli_stmt_bind_param($u, 'i', $task_id);
                        mysqli_stmt_execute($u);
                        mysqli_stmt_close($u);

                        $message = 'Course updated successfully!';
                        $messageType = 'success';
                    } else {
                        $message = 'Error updating course: ' . mysqli_stmt_error($s);
                        $messageType = 'error';
                    }
                    mysqli_stmt_close($s);
                } else {
                    $message = 'Error preparing update: ' . mysqli_error($conn);
                    $messageType = 'error';
                }
            } elseif ($task['task_type'] === 'delete') {
                $deleteCourseQuery = "DELETE FROM `" . $tableName . "` WHERE id = ?";
                $s = mysqli_prepare($conn, $deleteCourseQuery);
                if ($s) {
                    mysqli_stmt_bind_param($s, 'i', $task['course_id']);
                    if (mysqli_stmt_execute($s)) {
                        $u = mysqli_prepare($conn, "UPDATE tasks SET status='approved', updated_at=NOW() WHERE id=?");
                        mysqli_stmt_bind_param($u, 'i', $task_id);
                        mysqli_stmt_execute($u);
                        mysqli_stmt_close($u);

                        $message = 'Course deleted successfully!';
                        $messageType = 'success';
                    } else {
                        $message = 'Error deleting course: ' . mysqli_stmt_error($s);
                        $messageType = 'error';
                    }
                    mysqli_stmt_close($s);
                } else {
                    $message = 'Error preparing delete: ' . mysqli_error($conn);
                    $messageType = 'error';
                }
            }
        } else {
            // reject course
            $u = mysqli_prepare($conn, "UPDATE tasks SET status='rejected', updated_at=NOW() WHERE id=?");
            mysqli_stmt_bind_param($u, 'i', $task_id);
            if (mysqli_stmt_execute($u)) {
                $message = 'Request rejected.';
                $messageType = 'success';
            } else {
                $message = 'Error rejecting request: ' . mysqli_stmt_error($u);
                $messageType = 'error';
            }
            mysqli_stmt_close($u);
        }
    }

    // redirect to avoid duplicate form resubmission and to show message
    $redir = 'approve_tasks.php?type=' . ($type === 'student' ? 'student' : 'course');
    header('Location: ' . $redir . '&msg=' . urlencode($message) . '&t=' . ($messageType));
    exit;
}

// Prepare listing for requested type
if ($type === 'student') {
    $pendingQuery = "SELECT st.*, std.exam_roll_no, std.name, std.roll_no, std.father, std.mother, std.phone_no, std.parent_phone, u.username as submitted_by_name FROM student_tasks st LEFT JOIN student_task_details std ON st.id = std.task_id LEFT JOIN users u ON st.submitted_by = u.user_id WHERE st.status = 'pending' ORDER BY st.created_at DESC";
    $pendingRes = mysqli_query($conn, $pendingQuery);
    $pendingTasks = mysqli_fetch_all($pendingRes, MYSQLI_ASSOC);
    
    // For edit tasks, fetch current student data to show before/after
    foreach ($pendingTasks as $key => $task) {
        if ($task['task_type'] === 'edit' && !empty($task['student_id'])) {
            $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $task['table_name']);
            $currentQuery = "SELECT * FROM `" . $tableName . "` WHERE id = " . intval($task['student_id']);
            $result = mysqli_query($conn, $currentQuery);
            if ($result && mysqli_num_rows($result) > 0) {
                $pendingTasks[$key]['current_data'] = mysqli_fetch_assoc($result);
                mysqli_free_result($result);
            }
        }
    }

    $approvedQuery = "SELECT st.*, std.exam_roll_no, std.roll_no, std.name, std.father, std.mother, std.phone_no, std.parent_phone, u.username as submitted_by_name FROM student_tasks st LEFT JOIN student_task_details std ON st.id = std.task_id LEFT JOIN users u ON st.submitted_by = u.user_id WHERE st.status = 'approved' ORDER BY st.updated_at DESC LIMIT 10";
    $approvedRes = mysqli_query($conn, $approvedQuery);
    $approvedTasks = mysqli_fetch_all($approvedRes, MYSQLI_ASSOC);

    $rejectedQuery = "SELECT st.*, std.exam_roll_no, std.roll_no, std.name, std.father, std.mother, std.phone_no, std.parent_phone, u.username as submitted_by_name FROM student_tasks st LEFT JOIN student_task_details std ON st.id = std.task_id LEFT JOIN users u ON st.submitted_by = u.user_id WHERE st.status = 'rejected' ORDER BY st.updated_at DESC LIMIT 10";
    $rejectedRes = mysqli_query($conn, $rejectedQuery);
    $rejectedTasks = mysqli_fetch_all($rejectedRes, MYSQLI_ASSOC);
} else {
    $pendingQuery = "SELECT t.id, t.task_type, t.table_name, t.course_id, t.submitted_by, t.status, t.created_at, t.updated_at, td.course_code, td.subject, td.course_type, td.credits, td.internal_marks, td.external_marks, td.instructor, td.not_in_use, u.username as submitted_by_name FROM tasks t LEFT JOIN task_details td ON t.id = td.task_id LEFT JOIN users u ON t.submitted_by = u.user_id WHERE t.status = 'pending' ORDER BY t.created_at DESC";
    $pendingRes = mysqli_query($conn, $pendingQuery);
    $pendingTasks = mysqli_fetch_all($pendingRes, MYSQLI_ASSOC);
    
    // For edit tasks, fetch current course data to show before/after
    foreach ($pendingTasks as $key => $task) {
        if ($task['task_type'] === 'edit' && !empty($task['course_id'])) {
            $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $task['table_name']);
            $currentQuery = "SELECT * FROM `" . $tableName . "` WHERE id = " . intval($task['course_id']);
            $result = mysqli_query($conn, $currentQuery);
            if ($result && mysqli_num_rows($result) > 0) {
                $pendingTasks[$key]['current_data'] = mysqli_fetch_assoc($result);
                mysqli_free_result($result);
            }
        }
    }

    $approvedQuery = "SELECT t.id, t.task_type, t.table_name, t.course_id, t.submitted_by, t.status, t.created_at, t.updated_at, td.course_code, td.subject, td.course_type, td.credits, td.internal_marks, td.external_marks, td.instructor, td.not_in_use, u.username as submitted_by_name FROM tasks t LEFT JOIN task_details td ON t.id = td.task_id LEFT JOIN users u ON t.submitted_by = u.user_id WHERE t.status = 'approved' ORDER BY t.updated_at DESC LIMIT 10";
    $approvedRes = mysqli_query($conn, $approvedQuery);
    $approvedTasks = mysqli_fetch_all($approvedRes, MYSQLI_ASSOC);

    $rejectedQuery = "SELECT t.id, t.task_type, t.table_name, t.course_id, t.submitted_by, t.status, t.created_at, t.updated_at, td.course_code, td.subject, td.course_type, td.credits, td.internal_marks, td.external_marks, td.instructor, td.not_in_use, u.username as submitted_by_name FROM tasks t LEFT JOIN task_details td ON t.id = td.task_id LEFT JOIN users u ON t.submitted_by = u.user_id WHERE t.status = 'rejected' ORDER BY t.updated_at DESC LIMIT 10";
    $rejectedRes = mysqli_query($conn, $rejectedQuery);
    $rejectedTasks = mysqli_fetch_all($rejectedRes, MYSQLI_ASSOC);
}

// Show optional message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['t'] ?? 'success';
}

// Helper to convert table name to semester label
function getSemesterLabel($tableName) {
    $map = [
        'bca_i' => 'BCA 1st Semester', 'bca_ii' => 'BCA 2nd Semester', 'bca_iii' => 'BCA 3rd Semester',
        'bca_iv' => 'BCA 4th Semester', 'bca_v' => 'BCA 5th Semester', 'bca_vi' => 'BCA 6th Semester',
        'mca_i' => 'MCA 1st Semester', 'mca_ii' => 'MCA 2nd Semester', 'mca_iii' => 'MCA 3rd Semester',
        'mca_iv' => 'MCA 4th Semester', 'mca_v' => 'MCA 5th Semester', 'mca_vi' => 'MCA 6th Semester'
    ];
    return $map[strtolower($tableName)] ?? htmlspecialchars($tableName);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approve Tasks — CampusCloud</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/approve_course_request.css">
    <script defer src="styles/theme.js"></script>
</head>
<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title"><?php echo $type === 'student' ? "👥 Student Approval Manager" : "📋 Course Approval Manager"; ?></h1>
                <p class="muted"><?php echo $type === 'student' ? 'Review and approve pending student requests' : 'Review and approve pending course requests'; ?></p>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <a class="btn btn-outline" href="dashboard.php">← Back to Dashboard</a>
                <a class="btn btn-outline" href="approve_tasks.php?type=course">Course Approvals</a>
                <a class="btn btn-outline" href="approve_tasks.php?type=student">Student Approvals</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>">
                <strong><?php echo $messageType === 'success' ? 'Success!' : 'Error'; ?></strong> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <main>
            <!-- Pending Requests -->
            <section class="approval-section">
                <div class="section-header">
                    <h2>⏳ Pending Requests (<?php echo count($pendingTasks); ?>)</h2>
                    <span class="badge"><?php echo count($pendingTasks); ?></span>
                </div>

                <?php if (empty($pendingTasks)): ?>
                    <div class="empty-state"><p>✓ No pending requests at this time</p></div>
                <?php else: ?>
                    <div class="tasks-grid">
                        <?php foreach ($pendingTasks as $task): ?>
                            <div class="task-card task-card-pending">
                                <div class="task-header">
                                    <div class="task-title">
                                        <h3><?php echo htmlspecialchars($type === 'student' ? ($task['exam_roll_no'] ?? '') : ($task['course_code'] ?? '')); ?></h3>
                                        <p><?php echo htmlspecialchars($type === 'student' ? ($task['name'] ?? '') : ($task['subject'] ?? '')); ?></p>
                                    </div>
                                    <span class="badge badge-pending"><?php echo ucfirst(str_replace('_', ' ', $task['task_type'])); ?></span>
                                </div>

                                <!-- Task Description -->
                                <div style="background:rgba(255,255,255,0.05);padding:10px;border-radius:6px;margin-bottom:12px;font-size:0.9em;border-left:3px solid #4a9eff;">
                                    <?php if ($task['task_type'] === 'add'): ?>
                                        <div style="color:#51cf66;font-weight:500;">📌 New <?php echo $type === 'student' ? 'Student' : 'Course'; ?> Addition</div>
                                        <div style="color:#ccc;margin-top:4px;">A new <?php echo $type === 'student' ? 'student' : 'course'; ?> will be added to <?php echo getSemesterLabel($task['table_name']); ?> once approved.</div>
                                    <?php elseif ($task['task_type'] === 'edit'): ?>
                                        <div style="color:#ffd700;font-weight:500;">✏️ <?php echo $type === 'student' ? 'Student' : 'Course'; ?> Modification</div>
                                        <div style="color:#ccc;margin-top:4px;">Details of existing <?php echo $type === 'student' ? 'student' : 'course'; ?> will be updated in <?php echo getSemesterLabel($task['table_name']); ?> once approved.</div>
                                    <?php elseif ($task['task_type'] === 'delete'): ?>
                                        <div style="color:#ff6b6b;font-weight:500;">🗑️ <?php echo $type === 'student' ? 'Student' : 'Course'; ?> Deletion</div>
                                        <div style="color:#ccc;margin-top:4px;">This <?php echo $type === 'student' ? 'student' : 'course'; ?> will be permanently removed from <?php echo getSemesterLabel($task['table_name']); ?> once approved.</div>
                                    <?php endif; ?>
                                </div>

                                <div class="task-details">
                                    <div class="detail-row"><span class="label">Semester:</span><span class="value"><?php echo getSemesterLabel($task['table_name']); ?></span></div>
                                    
                                    <?php if ($type === 'student'): ?>
                                        <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.1);">
                                            <div style="font-weight:500;color:#4a9eff;margin-bottom:8px;">Student Details:</div>
                                            <div class="detail-row"><span class="label">Exam Roll No:</span><span class="value"><?php echo htmlspecialchars($task['exam_roll_no'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Roll No:</span><span class="value"><?php echo htmlspecialchars($task['roll_no'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Name:</span><span class="value"><?php echo htmlspecialchars($task['name'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Father:</span><span class="value"><?php echo htmlspecialchars($task['father'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Mother:</span><span class="value"><?php echo htmlspecialchars($task['mother'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Phone:</span><span class="value"><?php echo htmlspecialchars($task['phone_no'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Parent Phone:</span><span class="value"><?php echo htmlspecialchars($task['parent_phone'] ?? 'N/A'); ?></span></div>
                                        </div>
                                    <?php else: ?>
                                        <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.1);">
                                            <div style="font-weight:500;color:#4a9eff;margin-bottom:8px;">Course Details:</div>
                                            <div class="detail-row"><span class="label">Course Code:</span><span class="value"><?php echo htmlspecialchars($task['course_code'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Subject:</span><span class="value"><?php echo htmlspecialchars($task['subject'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Type:</span><span class="value"><?php echo htmlspecialchars($task['course_type'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Credits:</span><span class="value"><?php echo htmlspecialchars($task['credits'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Internal Marks:</span><span class="value"><?php echo htmlspecialchars($task['internal_marks'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">External Marks:</span><span class="value"><?php echo htmlspecialchars($task['external_marks'] ?? 'N/A'); ?></span></div>
                                            <div class="detail-row"><span class="label">Instructor:</span><span class="value"><?php echo htmlspecialchars($task['instructor'] ?? 'Not Assigned'); ?></span></div>
                                            <div class="detail-row"><span class="label">Status:</span><span class="value"><?php echo ($task['not_in_use'] ? 'Not In Use' : 'Active'); ?></span></div>
                                        </div>
                                    <?php endif; ?>

                                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.1);">
                                        <div style="font-weight:500;color:#4a9eff;margin-bottom:8px;">Submission Info:</div>
                                        <div class="detail-row"><span class="label">Submitted by:</span><span class="value"><?php echo htmlspecialchars($task['submitted_by_name'] ?? ''); ?> (ID: <?php echo htmlspecialchars($task['submitted_by'] ?? ''); ?>)</span></div>
                                        <div class="detail-row"><span class="label">Submitted on:</span><span class="value"><?php echo date('M d, Y H:i', strtotime($task['created_at'])); ?></span></div>
                                    </div>
                                    
                                    <?php if ($task['task_type'] === 'edit' && is_array($task['current_data']) && !empty($task['current_data'])): ?>
                                        <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,0.1);font-size:0.9em;">
                                            <div style="margin-bottom:8px;font-weight:500;color:#ffd700;">⚡ Changes:</div>
                                            <?php if ($type === 'student'): ?>
                                                <?php if (($task['exam_roll_no'] ?? '') !== ($task['current_data']['exam_roll_no'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Exam Roll No:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['exam_roll_no'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['exam_roll_no'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['name'] ?? '') !== ($task['current_data']['name'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Name:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['name'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['name'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['roll_no'] ?? '') !== ($task['current_data']['roll_no'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Roll No:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['roll_no'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['roll_no'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['phone_no'] ?? '') !== ($task['current_data']['phone_no'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Phone:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['phone_no'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['phone_no'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['father'] ?? '') !== ($task['current_data']['father'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Father:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['father'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['father'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['mother'] ?? '') !== ($task['current_data']['mother'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Mother:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['mother'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['mother'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['parent_phone'] ?? '') !== ($task['current_data']['parent_phone'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Parent Phone:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['parent_phone'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['parent_phone'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if (($task['course_code'] ?? '') !== ($task['current_data']['course_code'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Course Code:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['course_code'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['course_code'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['subject'] ?? '') !== ($task['current_data']['subject'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Subject:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['subject'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['subject'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['course_type'] ?? '') !== ($task['current_data']['course_type'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Type:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['course_type'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['course_type'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if ((int)($task['credits'] ?? 0) !== (int)($task['current_data']['credits'] ?? 0)): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Credits:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['credits'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['credits'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if ((int)($task['internal_marks'] ?? 0) !== (int)($task['current_data']['internal_marks'] ?? 0)): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Internal Marks:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['internal_marks'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['internal_marks'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if ((int)($task['external_marks'] ?? 0) !== (int)($task['current_data']['external_marks'] ?? 0)): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">External Marks:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['external_marks'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['external_marks'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if (($task['instructor'] ?? '') !== ($task['current_data']['instructor'] ?? '')): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Instructor:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo htmlspecialchars($task['current_data']['instructor'] ?? 'N/A'); ?></span> → <span style="color:#51cf66;"><?php echo htmlspecialchars($task['instructor'] ?? ''); ?></span></div>
                                                <?php endif; ?>
                                                <?php if ((int)($task['not_in_use'] ?? 0) !== (int)($task['current_data']['not_in_use'] ?? 0)): ?>
                                                    <div style="margin-bottom:6px;"><span style="color:#999;">Status:</span> <span style="text-decoration:line-through;color:#ff6b6b;"><?php echo ($task['current_data']['not_in_use'] ? 'Not In Use' : 'Active'); ?></span> → <span style="color:#51cf66;"><?php echo ($task['not_in_use'] ? 'Not In Use' : 'Active'); ?></span></div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <form method="POST" class="task-actions">
                                    <input type="hidden" name="task_id" value="<?php echo htmlspecialchars($task['id']); ?>">
                                    <textarea name="admin_remarks" placeholder="Add remarks (optional)" class="remarks-textarea"></textarea>
                                    <div class="action-buttons">
                                        <button type="submit" name="action" value="approve" class="btn btn-success">✓ Approve</button>
                                        <button type="submit" name="action" value="reject" class="btn btn-danger">✗ Reject</button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Approved & Rejected (summaries) -->
            <section class="approval-section">
                <div class="section-header"><h2>✓ Recently Approved</h2><span class="badge badge-success"><?php echo count($approvedTasks); ?></span></div>
                <?php if (empty($approvedTasks)): ?><div class="empty-state"><p>No approved requests yet</p></div>
                <?php else: ?>
                    <div class="history-table"><table><thead><tr><th>ID</th><th>Ref</th><th>Semester</th><th>Type</th><th>Submitted By</th><th>When</th></tr></thead><tbody>
                        <?php foreach ($approvedTasks as $t): ?>
                            <tr><td><?php echo htmlspecialchars($t['id']); ?></td><td><?php echo htmlspecialchars($type === 'student' ? ($t['exam_roll_no'] ?? '') : ($t['course_code'] ?? '')); ?></td><td><?php echo getSemesterLabel($t['table_name']); ?></td><td><?php echo htmlspecialchars($t['task_type']); ?></td><td><?php echo htmlspecialchars($t['submitted_by_name'] ?? ''); ?></td><td><?php echo date('M d, Y H:i', strtotime($t['updated_at'] ?? $t['created_at'])); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

        </main>
    </div>
</body>
</html>

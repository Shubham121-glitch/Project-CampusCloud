<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator', 'user']); // Users, moderators, and admins can submit
include __DIR__ . '/../db/connection.php';

// Get table name from URL
if (!isset($_GET['table'])) {
    die("No table specified.");
}
$table = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['table']); // Sanitize table name

$error = '';
$success = '';

// Fetch available instructors (moderators/faculty from users table)
$instructors = [];
$instructorQuery = "SELECT user_id, username FROM users WHERE role IN ('moderator', 'faculty') ORDER BY username ASC";
$instructorResult = mysqli_query($conn, $instructorQuery);
if ($instructorResult) {
    while ($row = mysqli_fetch_assoc($instructorResult)) {
        $instructors[$row['user_id']] = $row['username'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $course_code = trim($_POST['course_code'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $course_type = trim($_POST['course_type'] ?? '');
    $credits = intval($_POST['credits'] ?? 0);
    $internal_marks = intval($_POST['internal_marks'] ?? 0);
    $external_marks = intval($_POST['external_marks'] ?? 0);
    $instructor = trim($_POST['instructor'] ?? '');
    $not_in_use = isset($_POST['not_in_use']) ? 1 : 0;
    $submitted_by = $_SESSION['user_id']; // Track who submitted

    // Validation
    if (!$course_code || !$subject || !$course_type || $credits <= 0) {
        $error = "Please fill in all required fields with valid values.";
    } elseif ($internal_marks < 0 || $external_marks < 0) {
        $error = "Marks cannot be negative.";
    } elseif ($instructor && !in_array($instructor, array_keys($instructors))) {
        // Validate instructor exists in users table
        $error = "Selected instructor is not valid. Please choose from available instructors.";
    } else {
        // Check if course_code already exists in the actual course table
        $checkTable = mysqli_prepare($conn, "SELECT id FROM {$table} WHERE course_code = ? LIMIT 1");
        $duplicateInTable = false;
        if ($checkTable) {
            mysqli_stmt_bind_param($checkTable, 's', $course_code);
            mysqli_stmt_execute($checkTable);
            $checkTable->store_result();
            $duplicateInTable = ($checkTable->num_rows > 0);
            mysqli_stmt_close($checkTable);
        }
        
        // Check if a pending task for the same course_code already exists in this table
        $checkPending = mysqli_prepare($conn, "SELECT t.id FROM tasks t JOIN task_details td ON t.id = td.task_id WHERE t.table_name = ? AND td.course_code = ? AND t.status = 'pending' LIMIT 1");
        $duplicatePending = false;
        if ($checkPending) {
            mysqli_stmt_bind_param($checkPending, 'ss', $table, $course_code);
            mysqli_stmt_execute($checkPending);
            $checkPending->store_result();
            $duplicatePending = ($checkPending->num_rows > 0);
            mysqli_stmt_close($checkPending);
        }
        
        if ($duplicateInTable) {
            $error = "Course code already exists in this semester. Please use a unique course code.";
        } elseif ($duplicatePending) {
            $error = "A pending request with this course code already exists. Please wait for admin review or check your pending requests.";
        } else {
            // Remove previous non-pending tasks for same course (prevent duplicates)
        $cleanup = mysqli_prepare($conn, "DELETE t FROM tasks t JOIN task_details td ON t.id = td.task_id WHERE t.table_name = ? AND td.course_code = ? AND t.status != 'pending'");
        if ($cleanup) {
            mysqli_stmt_bind_param($cleanup, 'ss', $table, $course_code);
            mysqli_stmt_execute($cleanup);
            mysqli_stmt_close($cleanup);
        }

        // Insert into tasks table (main submission queue)
        // Also store full course data in a temp location for admin review
        $query = "INSERT INTO tasks (task_type, table_name, submitted_by, status) 
                  VALUES ('add', ?, ?, 'pending')";
        $stmt = mysqli_prepare($conn, $query);

        if ($stmt) {
            // types: table_name(s), submitted_by(i)
            mysqli_stmt_bind_param($stmt, 'si', $table, $submitted_by);

            if (mysqli_stmt_execute($stmt)) {
                $task_id = mysqli_insert_id($conn);
                
                // Store full course details in a separate column or table for admin to see
                // We'll store as JSON in tasks or create a course_details table
                // For now, insert into a temporary storage that we'll reference during approval
                $detailsQuery = "INSERT INTO task_details (task_id, course_code, subject, course_type, credits, internal_marks, external_marks, instructor, not_in_use) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $detailsStmt = mysqli_prepare($conn, $detailsQuery);
                
                if ($detailsStmt) {
                    mysqli_stmt_bind_param($detailsStmt, 'isssiiisi', $task_id, $course_code, $subject, $course_type, $credits, $internal_marks, $external_marks, $instructor, $not_in_use);
                    
                    if (mysqli_stmt_execute($detailsStmt)) {
                        $success = "Course request submitted successfully! It will be reviewed by an admin shortly.";
                        header("Refresh: 2; url=courses_bca.php");
                    } else {
                        $error = "Error storing course details: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($detailsStmt);
                } else {
                    $error = "Error preparing course details: " . mysqli_error($conn);
                }
            } else {
                $error = "Error submitting course request: " . mysqli_error($conn);
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = "Database error: " . mysqli_error($conn);
        }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Course — CampusCloud</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/add_course.css">
    <script defer src="styles/theme.js"></script>
</head>

<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title">Request New Course</h1>
                <p class="muted">Submit a new course for admin approval</p>
            </div>
            <a class="btn btn-outline" href="courses_bca.php">← Back to Courses</a>
        </header>

        <main>
            <div class="form-card">
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <strong>Success!</strong> <?php echo htmlspecialchars($success); ?> Redirecting...
                    </div>
                <?php endif; ?>

                <form method="POST" id="courseForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_code">Course Code *</label>
                            <input type="text" id="course_code" name="course_code" placeholder="e.g., CS101" required
                                maxlength="20">
                            <span class="hint">Unique identifier for the course</span>
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject Name *</label>
                            <input type="text" id="subject" name="subject" placeholder="e.g., Data Structures" required
                                maxlength="100">
                            <span class="hint">Full course title</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="course_type">Course Type *</label>
                            <select id="course_type" name="course_type" required>
                                <option value="">-- Select --</option>
                                <option value="Major">Major</option>
                                <option value="Minor">Minor</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="credits">Credits *</label>
                            <input type="number" id="credits" name="credits" min="1" max="10" required
                                placeholder="e.g., 3">
                            <span class="hint">Credit hours for this course</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="internal_marks">Internal Marks Max *</label>
                            <input type="number" id="internal_marks" name="internal_marks" min="0" max="100" required
                                placeholder="e.g., 30">
                            <span class="hint">Maximum internal assessment marks</span>
                        </div>

                        <div class="form-group">
                            <label for="external_marks">External Marks Max *</label>
                            <input type="number" id="external_marks" name="external_marks" min="0" max="100" required
                                placeholder="e.g., 70">
                            <span class="hint">Maximum external exam marks</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="instructor">Instructor (Faculty/Moderator) *</label>
                        <select id="instructor" name="instructor" required>
                            <option value="">-- Select Instructor --</option>
                            <?php foreach ($instructors as $user_id => $username): ?>
                                <option value="<?php echo htmlspecialchars($user_id); ?>">
                                    <?php echo htmlspecialchars($username); ?> (ID: <?php echo htmlspecialchars($user_id); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint">Select from available faculty/moderators or leave blank if not assigned</span>
                    </div>

                    <div class="form-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" id="not_in_use" name="not_in_use" value="1">
                            <span>Mark as not in use</span>
                        </label>
                        <span class="hint">Check this if the course is not currently active</span>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">✓ Submit for Approval</button>
                        <a href="courses_bca.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Form validation on submit
        document.getElementById('courseForm').addEventListener('submit', function (e) {
            const credits = parseInt(document.getElementById('credits').value);
            const internal = parseInt(document.getElementById('internal_marks').value);
            const external = parseInt(document.getElementById('external_marks').value);

            if (credits < 1 || credits > 10) {
                e.preventDefault();
                alert('Credits must be between 1 and 10');
                return false;
            }

            if (internal + external !== 100) {
                if (!confirm('Warning: Internal (' + internal + ') + External (' + external + ') = ' + (internal + external) + ', not 100. Continue?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });

        // Disable instructor field if not in use is checked
        document.getElementById('not_in_use').addEventListener('change', function () {
            const instructorField = document.getElementById('instructor');
            if (this.checked) {
                instructorField.disabled = true;
                instructorField.value = '';
            } else {
                instructorField.disabled = false;
            }
        });
    </script>
</body>

</html>

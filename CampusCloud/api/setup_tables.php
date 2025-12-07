<?php
// Simple table creation script
$host = 'localhost';
$user = 'root';
$password = 'shubham';
$db_name = 'campuscloud';

// Connect without database first
$conn = mysqli_connect($host, $user, $password);

if (!$conn) {
    die(json_encode(['error' => 'Connection failed: ' . mysqli_connect_error()]));
}

// Select database
mysqli_select_db($conn, $db_name);

$errors = [];
$success = [];

// SQL statements
$sql_statements = [
    "CREATE TABLE IF NOT EXISTS student_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_type ENUM('add', 'edit', 'delete') NOT NULL,
        table_name VARCHAR(100) NOT NULL,
        student_id INT DEFAULT NULL,
        submitted_by INT NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        admin_remarks TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (submitted_by) REFERENCES users(user_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS student_task_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        exam_roll_no VARCHAR(50) NOT NULL,
        roll_no VARCHAR(50),
        name VARCHAR(255),
        father VARCHAR(255),
        mother VARCHAR(255),
        phone_no VARCHAR(20),
        parent_phone VARCHAR(20),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES student_tasks(id) ON DELETE CASCADE,
        UNIQUE KEY unique_exam_roll (exam_roll_no)
    )"
,
    // Tasks for course-level requests
    "CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_type ENUM('add', 'edit', 'delete') NOT NULL,
        table_name VARCHAR(100) NOT NULL,
        course_id INT DEFAULT NULL,
        submitted_by INT NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (submitted_by) REFERENCES users(user_id) ON DELETE SET NULL
    )",

    "CREATE TABLE IF NOT EXISTS task_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        course_code VARCHAR(100),
        subject VARCHAR(255),
        course_type VARCHAR(50),
        credits INT DEFAULT 0,
        internal_marks INT DEFAULT 0,
        external_marks INT DEFAULT 0,
        instructor VARCHAR(255),
        not_in_use TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
    )"
];

// Execute statements
foreach ($sql_statements as $sql) {
    if (mysqli_query($conn, $sql)) {
        $success[] = "Table created successfully";
    } else {
        $errors[] = mysqli_error($conn);
    }
}

// Add constraints to student tables
$tables = [
    'bca_student_i', 'bca_student_ii', 'bca_student_iii', 'bca_student_iv', 'bca_student_v', 'bca_student_vi',
    'mca_student_i', 'mca_student_ii', 'mca_student_iii', 'mca_student_iv', 'mca_student_v', 'mca_student_vi'
];

foreach ($tables as $table) {
    $sql = "ALTER TABLE `$table` ADD UNIQUE KEY unique_exam_roll_no (exam_roll_no)";
    mysqli_query($conn, $sql); // Ignore errors if already exists
    $success[] = "Processed constraint for $table";
}

mysqli_close($conn);

echo json_encode([
    'success' => $success,
    'errors' => $errors,
    'status' => count($errors) === 0 ? 'OK' : 'ERROR'
]);
?>

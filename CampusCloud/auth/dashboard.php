<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin', 'moderator']);
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username']);
?>

<?php
// Assuming you already have a database connection in $conn
// Example connection (adjust with your DB credentials)
include_once __DIR__ . '/../db/connection.php';

// Handle notification clearing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_notification') {
  if ($role === 'moderator') {
    $notification_id = intval($_POST['notification_id'] ?? 0);
    // Mark as read/hidden for moderator (we'll add a read status column)
    // For now, we'll use a simple approach with session-based hiding
    if (!isset($_SESSION['hidden_notifications'])) {
      $_SESSION['hidden_notifications'] = [];
    }
    $_SESSION['hidden_notifications'][] = $notification_id;
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
  }
}

// Handle admin profile name update
$profile_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile_name') {
    $newName = trim($_POST['profile_name'] ?? '');
    if ($newName === '') {
      $profile_msg = 'Profile name cannot be empty.';
    } else {
      $stmt = $conn->prepare("UPDATE users SET username = ? WHERE user_id = ?");
      if ($stmt) {
        $stmt->bind_param('si', $newName, $user_id);
        if ($stmt->execute()) {
          $_SESSION['username'] = $newName;
          $username = htmlspecialchars($newName);
          $profile_msg = 'Profile name updated.';
        } else {
          $profile_msg = 'Error updating profile: ' . $stmt->error;
        }
        $stmt->close();
      } else {
        $profile_msg = 'Prepare failed: ' . $conn->error;
      }
    }
  }



// Fetch tasks
  if ($role === 'admin') {
  // Admin sees pending course requests, student requests and internal mark proposals
  $taskStmt = $conn->prepare("SELECT t.id, t.task_type, t.table_name, td.course_code, td.subject, t.status, t.created_at, 'course' as source
                              FROM tasks t
                              LEFT JOIN task_details td ON t.id = td.task_id
                              WHERE t.status = 'pending'
                              UNION
                              SELECT st.id, st.task_type, st.table_name, NULL as course_code, std.name as subject, st.status, st.created_at, 'student' as source
                              FROM student_tasks st
                              LEFT JOIN student_task_details std ON st.id = std.task_id
                              WHERE st.status = 'pending'
                              UNION
                              SELECT imr.id, 'internal' as task_type, imr.course_table as table_name,
                                     c.course_code as course_code,
                                     CONCAT('Proposed ', imr.proposed_marks, ' — ', COALESCE(s.name, CONCAT(imr.student_table, '#', imr.student_id))) as subject,
                                     imr.status, imr.created_at, 'internal' as source
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
                              WHERE imr.status = 'pending'
                              ORDER BY created_at DESC");
  if (!$taskStmt) {
    die("Admin query prepare error: " . $conn->error);
  }
  $taskStmt->execute();
  $taskResult = $taskStmt->get_result();
  } elseif ($role === 'moderator') {
  // Moderators see their own course/student submissions and their internal mark proposals
  $uid = $_SESSION['user_id'];
  $taskStmt = $conn->prepare("SELECT t.id, t.task_type, t.table_name, td.course_code, td.subject, t.status, t.created_at, 'course' as source
                              FROM tasks t
                              LEFT JOIN task_details td ON t.id = td.task_id
                              WHERE t.submitted_by = ? 
                              UNION
                              SELECT st.id, st.task_type, st.table_name, NULL as course_code, std.name as subject, st.status, st.created_at, 'student' as source
                              FROM student_tasks st
                              LEFT JOIN student_task_details std ON st.id = std.task_id
                              WHERE st.submitted_by = ?
                              UNION
                              SELECT imr.id, 'internal' as task_type, imr.course_table as table_name,
                                     c.course_code as course_code,
                                     CONCAT('Proposed ', imr.proposed_marks, ' — ', COALESCE(s.name, CONCAT(imr.student_table, '#', imr.student_id))) as subject,
                                     imr.status, imr.created_at, 'internal' as source
                              FROM internal_mark_requests imr
                              LEFT JOIN (
                                SELECT id, name, 'bca_i' as t FROM bca_student_i
                                UNION SELECT id, name, 'bca_ii' FROM bca_student_ii
                                UNION SELECT id, name, 'bca_iii' FROM bca_student_iii
                                UNION SELECT id, name, 'bca_iv' FROM bca_student_iv
                                UNION SELECT id, name, 'bca_v' FROM bca_student_v
                                UNION SELECT id, name, 'bca_vi' FROM bca_student_vi
                              ) s ON (s.id = imr.student_id AND s.t = imr.student_table)
                              LEFT JOIN (
                                SELECT id, course_code, subject, 'bca_i' as t FROM bca_i
                                UNION SELECT id, course_code, subject, 'bca_ii' FROM bca_ii
                                UNION SELECT id, course_code, subject, 'bca_iii' FROM bca_iii
                                UNION SELECT id, course_code, subject, 'bca_iv' FROM bca_iv
                                UNION SELECT id, course_code, subject, 'bca_v' FROM bca_v
                                UNION SELECT id, course_code, subject, 'bca_vi' FROM bca_vi
                              ) c ON (c.id = imr.course_id AND c.t = imr.course_table)
                              WHERE imr.submitted_by = ?
                              ORDER BY created_at DESC");
  if (!$taskStmt) {
    die("Moderator query prepare error: " . $conn->error);
  }
  $taskStmt->bind_param("iii", $uid, $uid, $uid);
  $taskStmt->execute();
  $taskResult = $taskStmt->get_result();
} else {
  $taskResult = null;
}

$tasks = [];
if ($taskResult && $taskResult->num_rows > 0) {
  while ($row = $taskResult->fetch_assoc()) {
    // Filter out hidden notifications for moderators
    if ($role === 'moderator' && isset($_SESSION['hidden_notifications']) && in_array($row['id'], $_SESSION['hidden_notifications'])) {
      continue;
    }
    $tasks[] = $row;
  }
}

?>



<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard — CampusCloud</title>

  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    integrity="sha512-dx1a3+PiOfrvLe1k+Yw2eVylPQIeN/DFP6B4KXzS1ec7x4Fbs+mLpjB6+rnbRtbzGqxUFXrN1u6w5xz+ZjVbNg=="
    crossorigin="anonymous" referrerpolicy="no-referrer" />

  <link rel="stylesheet" href="styles/dashboard.css">
  <script defer src="styles/theme.js"></script>
  <script defer src="styles/todo.js"></script>
</head>

<body>
  <button id="mobile-sidebar-toggle" class="mobile-sidebar-toggle">☰</button>


  <div class="layout">

    <!-- ==========================
       SIDEBAR
  =========================== -->
    <aside class="sidebar">

      <div class="sidebar-top">
        <div class="brand">CampusCloud</div>

        <div class="profile-box">
          <div class="avatar"><?php echo strtoupper($username[0]); ?></div>
          <div>
            <div class="username">
              <?php echo $username; ?>
              <?php if ($role === 'admin'): ?>
                <button id="edit-profile-btn" title="Edit profile name" class="btn btn-sm">✎</button>
              <?php endif; ?>
            </div>
            <div class="role"><?php echo $role; ?></div>
            <p>User id: <?php echo $user_id ?></p>

            <?php if ($role === 'admin'): ?>
              <form id="edit-profile-form" method="POST" style="display:none; margin-top:8px;">
                <input type="hidden" name="action" value="update_profile_name">
                <input type="text" name="profile_name" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" placeholder="Profile name" required>
                <button type="submit" class="btn">Save</button>
                <button type="button" id="cancel-edit-profile" class="btn btn-outline">Cancel</button>
              </form>
              <?php if (!empty($profile_msg)): ?>
                <div class="muted" style="margin-top:6px; font-size:12px;"><?php echo htmlspecialchars($profile_msg); ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <nav class="menu">
          <a href="landing.php" class="menu-item">🏡 Home</a>
          <a href="#" class="menu-item active">🏠 Dashboard</a>

          <div class="menu-label">Academic</div>
          <a href="courses_bca.php" class="menu-item">📘 BCA Courses</a>
          <a href="courses_mca.php" class="menu-item">📗 MCA Courses</a>

          <div class="menu-label">Students</div>
          <a href="students_bca.php" class="menu-item">👥 BCA Students</a>
          <a href="students_mca.php" class="menu-item">👥 MCA Students</a>

          <div class="menu-label">Attendance</div>
          <a href="attendance_bca.php" class="menu-item">📝 BCA Attendance</a>
          <a href="attendance_mca.php" class="menu-item">📝 MCA Attendance</a>
          <a href="overall_attandance.php" class="menu-item">📅 Overall Datewise Attendance</a>
          <?php if ($role === 'moderator'): ?>
            <a href="overall_attandance_moderator.php" class="menu-item">🔎 My Attendance Overview</a>
          <?php endif; ?>

          <div class="menu-label">Marks</div>
          <a href="internal_bca.php" class="menu-item">📊 BCA Internal Marks</a>
          <a href="internal_mca.php" class="menu-item">📊 MCA Internal Marks</a>

          <div class="menu-label">Activity</div>
          <!-- Activity pages removed: links hidden to avoid broken references -->

          <?php if ($role === 'admin'): ?>
            <div class="menu-label">Admin</div>
            <a href="faculty.php" class="menu-item">🧑‍🏫 Manage Faculty</a>
            <a href="approve_tasks.php?type=course" class="menu-item">✅ Approve Course Requests</a>
            <a href="approve_tasks.php?type=student" class="menu-item">👥 Approve Student Requests</a>
            <a href="internal_mark_approvals.php" class="menu-item">📩 Approve Internal Marks</a>
            <a href="tasks_history.php" class="menu-item">📜 Tasks History</a>
          <?php endif; ?>
        </nav>
      </div>

      <div class="sidebar-bottom">
        <a href="logout.php" class="menu-item danger">🚪 Logout</a>
        <button class="theme-toggle" id="theme-toggle">🌙</button>
      </div>

    </aside>

    <!-- ==========================
       MAIN CONTENT
  =========================== -->
    <main class="main">

      <!-- HERO -->
      <section class="hero">
        <div class="hero-text">
          <h1>Welcome back, <?php echo $username; ?> 👋</h1>
          <p>Your personalized academic dashboard with everything you need in one place.</p>

          <div class="hero-buttons">
            <a href="courses_bca.php" class="btn btn-primary">Browse Courses</a>
            <a href="students_bca.php" class="btn btn-outline">View Students</a>
          </div>
        </div>

        <div class="hero-img">
          <ul class="social-shortcuts">
            <li title="Facebook">
              <a href="https://facebook.com" target="_blank" class="facebook" aria-label="Facebook">
                <!-- SVG fallback for Facebook -->
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path
                    d="M22 12.07C22 6.48 17.52 2 11.93 2S2 6.48 2 12.07c0 4.99 3.66 9.13 8.44 9.93v-7.04H8.08v-2.9h2.36V9.41c0-2.33 1.38-3.61 3.5-3.61.99 0 1.98.18 1.98.18v2.18h-1.12c-1.1 0-1.44.68-1.44 1.38v1.65h2.45l-.39 2.9h-2.06v7.04C18.34 21.2 22 17.06 22 12.07z" />
                </svg>
              </a>
            </li>
            <li title="Instagram">
              <a href="https://instagram.com" target="_blank" class="instagram" aria-label="Instagram">
                <!-- SVG fallback for Instagram -->
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                  stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <rect x="3" y="3" width="18" height="18" rx="5"></rect>
                  <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                  <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                </svg>
              </a>
            </li>
            <li title="Google">
              <a href="https://google.com" target="_blank" class="google" aria-label="Google">
                <!-- SVG fallback for Google -->
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path
                    d="M21.6 12.23c0-.68-.06-1.33-.18-1.96H12v3.72h5.48c-.24 1.28-.98 2.37-2.09 3.1v2.58h3.38c1.97-1.81 3.23-4.5 3.23-7.44z"
                    fill="#4285F4" />
                  <path
                    d="M12 22c2.7 0 4.97-.9 6.63-2.43l-3.38-2.58c-.94.63-2.14 1.01-3.25 1.01-2.5 0-4.62-1.68-5.38-3.95H2.98v2.48C4.63 19.94 8.05 22 12 22z"
                    fill="#34A853" />
                  <path
                    d="M6.62 14.05A6.98 6.98 0 0 1 6 12c0-.66.1-1.3.29-1.9V7.62H2.98A10 10 0 0 0 2 12c0 1.6.37 3.12 1.02 4.48l2.6-2.43z"
                    fill="#FBBC05" />
                  <path
                    d="M12 6.5c1.47 0 2.8.5 3.85 1.48l2.87-2.87C16.95 3.53 14.7 2.5 12 2.5 8.05 2.5 4.63 4.56 2.98 7.38l3.93 2.74C7.38 8.18 9.5 6.5 12 6.5z"
                    fill="#EA4335" />
                </svg>
              </a>
            </li>
            <li title="Whatsapp">
              <a href="https://wa.me/" target="_blank" class="whatsapp" aria-label="WhatsApp">
                <!-- SVG fallback for WhatsApp -->
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                  <path
                    d="M20.52 3.48A11.94 11.94 0 0 0 12.06 0C5.54 0 .5 5.04.5 11.56c0 2.03.53 4.01 1.54 5.74L0 24l6.9-2.25A11.5 11.5 0 0 0 12.06 23c6.52 0 11.56-5.04 11.56-11.56 0-3.09-1.2-5.98-3.1-8.0zM12.06 20.2c-1.46 0-2.9-.4-4.15-1.16l-.3-.18-4.1 1.34 1.32-3.92-.2-.33A8.9 8.9 0 0 1 3.16 11.56 8.9 8.9 0 0 1 12.06 2.66c4.95 0 9 4.05 9 9.03 0 4.98-4.05 9.03-9 9.03z" />
                </svg>
              </a>
            </li>
          </ul>
        </div>

      </section>

      <!-- PANELS -->
      <section class="quick-panels">

        <div class="dashboard-pannels">
          <div class="todo-list">
            <h3><?php echo strtoupper("$role's To-do's"); ?></h3>
            <div class="todo-input">
              <input type="text" id="todo-input" placeholder="Add a new task...">
              <button id="add-todo">Add</button>
            </div>
            <ul id="todo-items" class="todo-items"></ul>
          </div>

          <div class="notifications-header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <div>
                <h3>
                  📋 <?php echo $role === 'admin' ? 'Course Requests' : 'My Submissions'; ?>
                  <?php if ($role === 'admin' && count($tasks) > 0): ?>
                    <span class="badge pending"><?php echo count(array_filter($tasks, fn($t) => $t['status'] === 'pending')); ?> Pending</span>
                  <?php endif; ?>
                </h3>
                <p class="subtitle">
                  <?php echo $role === 'admin' ? 'Review and approve pending course requests' : 'Track your course submissions'; ?>
                </p>
              </div>
              <?php if ($role === 'moderator' && count($tasks) > 0): ?>
                <button class="btn btn-outline btn-sm" onclick="clearAllNotifications()" title="Clear all notifications">
                  🗑️ Clear All
                </button>
              <?php endif; ?>
            </div>
          </div>

          <?php if (empty($tasks)): ?>
            <div class="empty-state">
              <div class="empty-icon">No notifications</div>
              <p>No tasks to show at the moment.</p>
            </div>
          <?php else: ?>
            <div class="task-list">
              <?php foreach ($tasks as $task):
                $isPending = $task['status'] === 'pending';
                $isApproved = $task['status'] === 'approved';
                $isRejected = $task['status'] === 'rejected';
                $taskTypeLabel = strtoupper($task['task_type'] ?? 'REQUEST');
                $courseCode = $task['course_code'] ?? $task['subject'] ?? 'N/A';
                $subject = $task['subject'] ?? 'Submission';
                $taskUrl = $role === 'admin' ? 'approve_tasks.php' : 'tasks_history.php';
                $isStudentTask = $task['source'] === 'student';
              ?>
              <div
                class="task-card <?= $isPending ? 'status-pending' : ($isApproved ? 'status-approved' : 'status-rejected') ?>">
                <div class="task-content">
                  <h4><?php echo htmlspecialchars($courseCode); ?></h4>
                  <div class="task-meta">
                    <span class="status-badge <?= $task['status'] ?>">
                      <?= ucfirst($task['status']) ?>
                    </span>
                    <span class="type-badge">
                      <?php echo ($isStudentTask ? '👥 ' : '📘 ') . $taskTypeLabel; ?>
                    </span>
                    <span class="subject">
                      <?php echo htmlspecialchars($subject); ?>
                    </span>
                  </div>
                </div>

                <div class="task-actions">
                  <?php if ($role === 'admin' && $isPending): ?>
                    <?php if ($task['source'] === 'internal'): ?>
                      <a href="internal_mark_approvals.php" class="btn btn-primary btn-outline" title="Review">Review</a>
                    <?php else: ?>
                      <a href="<?php echo $isStudentTask ? 'approve_tasks.php?type=student' : 'approve_tasks.php?type=course'; ?>" class="btn btn-primary btn-outline" title="Review">Review</a>
                    <?php endif; ?>
                  <?php elseif ($role !== 'admin'): ?>
                    <a href="tasks_history.php" class="btn btn-outline" title="View Details">View</a>
                    <button class="btn btn-outline btn-danger" onclick="clearNotification(<?php echo $task['id']; ?>)" title="Clear notification">✕</button>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

        </div>



      </section>

    </main>

  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const sidebar = document.querySelector('.sidebar');
      const toggleBtn = document.getElementById('mobile-sidebar-toggle');

      toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('show');
      });

      // Close sidebar when clicking outside on mobile
      document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && sidebar.classList.contains('show')) {
          if (!sidebar.contains(e.target) && e.target !== toggleBtn) {
            sidebar.classList.remove('show');
          }
        }
      });

      // Profile edit toggle (admin only)
      const editBtn = document.getElementById('edit-profile-btn');
      const editForm = document.getElementById('edit-profile-form');
      const cancelBtn = document.getElementById('cancel-edit-profile');
      if (editBtn && editForm) {
        editBtn.addEventListener('click', (e) => {
          e.preventDefault();
          editForm.style.display = editForm.style.display === 'none' ? 'block' : 'none';
        });
      }
      if (cancelBtn && editForm) {
        cancelBtn.addEventListener('click', () => {
          editForm.style.display = 'none';
        });
      }
    });

    // Clear individual notification (moderator only)
    function clearNotification(notificationId) {
      if (confirm('Remove this notification?')) {
        fetch('<?php echo $_SERVER["PHP_SELF"]; ?>', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: 'action=clear_notification&notification_id=' + notificationId
        }).then(() => location.reload());
      }
    }

    // Clear all notifications (moderator only)
    function clearAllNotifications() {
      if (confirm('Clear all notifications? This cannot be undone.')) {
        const cards = document.querySelectorAll('.task-card');
        cards.forEach(card => {
          const btn = card.querySelector('button[onclick*="clearNotification"]');
          if (btn) {
            const notifId = btn.onclick.toString().match(/\d+/)[0];
            fetch('<?php echo $_SERVER["PHP_SELF"]; ?>', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
              },
              body: 'action=clear_notification&notification_id=' + notifId
            });
          }
        });
        setTimeout(() => location.reload(), 300);
      }
    }
  </script>



</body>

</html>
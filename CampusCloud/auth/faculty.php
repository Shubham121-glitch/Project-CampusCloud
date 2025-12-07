<?php
include './require_role.php';
require_roles(['admin']); // Only admin can manage faculty

include '../db/connection.php'; // This should contain $conn = new mysqli(...);

// Fetch all faculty members
$query = "SELECT user_id, username, pin, role, created_at 
          FROM users 
          WHERE role = 'moderator' 
          ORDER BY created_at DESC";

$result = $conn->query($query);

$faculty = [];

if ($result && $result->num_rows > 0) {
  while ($row = $result->fetch_assoc()) {
    $faculty[] = $row;
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Manage Faculty — CampusCloud</title>
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="styles/students.css">
  <script defer src="styles/theme.js"></script>
  <style>
    .page-title {
      font-size: 2.2rem;
      font-weight: 800;
      margin: 0;
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .header-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
    }

    .btn-small {
      padding: 8px 14px;
      font-size: 0.9rem;
    }
  </style>
</head>

<body>

  <div class="container">
    <!-- Header matching your courses.php style -->
    <header class="page-header">
      <div>
        <h1 class="page-title">Manage Faculty</h1>
        <div class="muted">Add, edit, or remove faculty members securely</div>
      </div>
      <div class="header-actions">
        <a href="dashboard.php" class="btn btn-small btn-outline">Back to Dashboard</a>
        <a href="faculty_add.php" class="btn btn-small btn-primary">+ Add Faculty</a>
        <a href="logout.php" class="btn btn-small" style="background:var(--danger);color:white;">Logout</a>
      </div>
    </header>

    <main>
      <!-- Search Toolbar -->
      <div class="table-toolbar">
        <div class="search-input" role="search" aria-label="Search faculty">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" style="margin-right:10px;opacity:0.7">
            <path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
            <circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
          </svg>
          <input type="search" id="faculty-search" class="search-box" placeholder="Search by username, PIN, or ID...">
        </div>
        <div class="search-count" id="search-count">Showing <?php echo count($faculty); ?> faculty</div>
      </div>

      <!-- Faculty Table -->
      <?php if (count($faculty) > 0): ?>
        <div class="table-responsive">
          <table class="table">
            <colgroup>
              <col style="width:12%">
              <col style="width:28%">
              <col style="width:18%">
              <col style="width:16%">
              <col style="width:18%">
              <col style="width:14%">
              <col style="width:14%">
            </colgroup>
            <thead>
              <tr>
                <th class="center">ID</th>
                <th>Username</th>
                <th class="center">PIN</th>
                <th class="center">Role</th>
                <th>Joined On</th>
                <th class="center">Edit</th>
                <th class="center">Delete</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($faculty as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['user_id']) ?></td>
                  <td><?= htmlspecialchars($row['username']) ?></td>
                  <td><?= htmlspecialchars($row['pin']) ?></td>
                  <td><?= htmlspecialchars($row['role']) ?></td>
                  <td><?= htmlspecialchars($row['created_at']) ?></td>
                  <td>
                    <a href="edit_faculty.php?id=<?= $row['user_id'] ?>" class="btn-action btn-edit"
                      title="Edit <?= htmlspecialchars($row['username']) ?>">
                </tr>
              <?php endforeach; ?>

            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="card muted" style="text-align:center;padding:60px 20px;">
          <div style="font-size:4rem;opacity:0.2;margin-bottom:16px;">No faculty members</div>
          <p>No faculty accounts found in the system.</p>
          <a href="faculty_add.php" class="btn btn-primary" style="margin-top:16px;">+ Add Your First Faculty</a>
        </div>
      <?php endif; ?>
    </main>
  </div>

  <!-- Client-side Search (same as courses.php) -->
  <script>
    (function () {
      const input = document.getElementById('faculty-search');
      const countEl = document.getElementById('search-count');
      const rows = Array.from(document.querySelectorAll('tbody tr'));

      function normalize(s) { return (s || '').toString().toLowerCase().trim(); }

      function filter(q) {
        q = normalize(q);
        let visible = 0;
        rows.forEach(tr => {
          const text = normalize(tr.textContent);
          const match = q === '' || text.includes(q);
          tr.style.display = match ? '' : 'none';
          if (match) visible++;
        });
        countEl.textContent = q === '' ? `Showing all ${rows.length}` : `Showing ${visible} of ${rows.length}`;
      }

      const debounce = (fn, ms) => {
        let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
      };

      input.addEventListener('input', debounce(e => filter(e.target.value), 200));
      countEl.textContent = `Showing all ${rows.length}`;
    })();
  </script>

</body>

</html>
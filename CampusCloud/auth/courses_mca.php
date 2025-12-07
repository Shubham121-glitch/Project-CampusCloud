<?php
include_once __DIR__ . '/require_role.php';
// Courses visible to all roles (view or manage depends on role in real implementation)
require_roles(['admin', 'moderator', 'user']);

// database connection
include __DIR__ . '/../db/connection.php';

$message = isset($_GET['msg']) ? $_GET['msg'] : '';
$messageType = isset($_GET['t']) ? $_GET['t'] : 'success';

// helper to render a course table for a given query result
function render_course_table($result, $tableName)
{
    if (!$result || mysqli_num_rows($result) === 0) {
        echo '<div class="card muted">No courses added yet.</div>';
        return;
    }

    echo '<div class="table-responsive"><table class="table">';
    echo '<colgroup><col style="width:12%"><col style="width:28%"><col style="width:12%"><col style="width:8%"><col style="width:8%"><col style="width:8%"><col style="width:14%"><col style="width:10%"></colgroup>';
    echo '<thead><tr><th>Course Code</th><th>Subject</th><th>Type</th><th>Credits</th><th>Internal</th><th>External</th><th>Instructor</th><th>Actions</th></tr></thead>';
    echo '<tbody>';
    
    while ($row = mysqli_fetch_assoc($result)) {
        echo '<tr>';
        echo '<td class="mono">' . htmlspecialchars($row['course_code']) . '</td>';
        echo '<td>' . htmlspecialchars($row['subject']) . '</td>';
        echo '<td>' . htmlspecialchars($row['course_type']) . '</td>';
        echo '<td class="center">' . htmlspecialchars($row['credits']) . '</td>';
        echo '<td class="center">' . htmlspecialchars($row['internal_marks']) . '</td>';
        echo '<td class="center">' . htmlspecialchars($row['external_marks']) . '</td>';
        echo '<td>' . htmlspecialchars($row['instructor'] ?? '') . '</td>';
        echo '<td class="actions">';
        echo '<a class="btn-edit" href="update_course.php?table=' . htmlspecialchars($tableName) . '&id=' . $row['id'] . '">Edit</a> ';
        echo '<a class="btn-delete" href="delete_course.php?table=' . htmlspecialchars($tableName) . '&id=' . $row['id'] . '" onclick="return confirm(\'Delete this course?\')">Delete</a>';
        echo '</td></tr>';
    }
    
    echo '</tbody></table></div>';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Courses — MCA</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/students.css">
    <script defer src="styles/theme.js"></script>
</head>

<body>
    <div class="container">
        <header class="page-header">
            <div>
                <h1 class="page-title">MCA Courses</h1>
                <div class="muted">Manage MCA courses and subjects</div>
            </div>
            <div>
                <a class="btn btn-small btn-outline" href="dashboard.php">Back to Dashboard</a>
                <a class="btn btn-small btn-primary" href="logout.php">Logout</a>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>" style="margin: 15px 0;"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <main>
            <div class="management-controls">
                <a class="btn btn-primary" href="add_course.php?table=mca_i">+ Add Course</a>
            </div>

            <div class="table-toolbar">
                <div class="search-input" role="search" aria-label="Search courses">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="margin-right:8px;opacity:0.7">
                        <path d="M21 21l-4.35-4.35" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <circle cx="11" cy="11" r="6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                    <input id="course-search" class="search-box" type="search" placeholder="Search courses, subject, instructor..." />
                </div>
                <div class="search-count" id="search-count">Showing all</div>
            </div>

            <center>
                <?php 
                $courseTerms = [
                    'mca_i' => 'MCA 1st Semester',
                    'mca_ii' => 'MCA 2nd Semester',
                    'mca_iii' => 'MCA 3rd Semester',
                    'mca_iv' => 'MCA 4th Semester',
                    'mca_v' => 'MCA 5th Semester',
                    'mca_vi' => 'MCA 6th Semester'
                ];
                
                foreach ($courseTerms as $tableName => $label): ?>
                    <section class="section-card card" data-term="<?php echo htmlspecialchars($tableName); ?>">
                        <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;">
                            <h2 style="margin:0"><?php echo htmlspecialchars($label); ?></h2>
                            <div>
                                <a class="btn btn-sm" href="add_course.php?table=<?php echo urlencode($tableName); ?>">+ Add Course</a>
                            </div>
                        </div>
                        <?php 
                        $query = "SELECT * FROM $tableName";
                        $result = mysqli_query($conn, $query);
                        render_course_table($result, $tableName);
                        ?>
                    </section>
                    <hr>
                <?php endforeach; ?>
            </center>

            <hr>

            <hr>

        </main>

    </div>

    <script>
        // Debounced client-side search for course tables
        (function () {
            const input = document.getElementById('course-search');
            const countEl = document.getElementById('search-count');

            const rows = Array.from(document.querySelectorAll('.table tbody tr'));
            const sections = Array.from(document.querySelectorAll('.section-card'));

            function normalize(s) { return (s || '').toString().toLowerCase(); }

            function updateCount(visible) {
                if (visible === rows.length) countEl.textContent = 'Showing all';
                else countEl.textContent = `Showing ${visible} of ${rows.length}`;
            }

            function filter(q) {
                q = normalize(q);
                // first hide/show rows based on query
                rows.forEach(tr => {
                    const text = normalize(tr.textContent);
                    const ok = q === '' || text.indexOf(q) !== -1;
                    tr.style.display = ok ? '' : 'none';
                });

                // then hide entire sections that have no visible rows
                let visibleTotal = 0;
                sections.forEach(section => {
                    const sectionRows = Array.from(section.querySelectorAll('.table tbody tr'));
                    const anyVisible = sectionRows.some(r => r.style.display !== 'none');
                    section.style.display = anyVisible ? '' : 'none';
                    if (anyVisible) {
                        visibleTotal += sectionRows.filter(r => r.style.display !== 'none').length;
                    }
                });

                updateCount(visibleTotal);
            }

            function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

            input.addEventListener('input', debounce((e) => filter(e.target.value), 180));
            // initialize
            updateCount(rows.length);
        })();
    </script>

    </main>
    </div>
</body>

</html>
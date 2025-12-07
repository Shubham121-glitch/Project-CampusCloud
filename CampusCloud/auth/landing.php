<?php
session_start();
$logged_in = isset($_SESSION['username']) && !empty($_SESSION['username']);
$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$accountLink = 'auth.php';

if ($logged_in) {
    $accountLink = 'dashboard.php';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>CampusCloud — Welcome</title>
    <link rel="stylesheet" href="styles/main.css">
    <link rel="stylesheet" href="styles/landing.css">
    <script defer src="styles/theme.js"></script>
</head>

<body>
    <div class="container">
        <nav class="nav" aria-label="Main navigation">
            <div class="brand">CampusCloud</div>

            <input type="checkbox" id="nav-toggle" aria-hidden="true">
            <label for="nav-toggle" aria-hidden="true">☰</label>

            <div class="nav-links">
                <a href="landing.php">Home</a>
                <a href="#about">About</a>
                <a href="#contact">Contact</a>

                <?php if ($logged_in): ?>
                    <a href="<?php echo $accountLink; ?>">Dashboard</a>

                    <span class="divider">|</span>
                    <span class="username"><?php echo htmlspecialchars($_SESSION['username']); ?></span>

                    <a class="logout-link" href="logout.php">Logout</a>
                <?php else: ?>
                    <a href="auth.php">Login</a>
                <?php endif; ?>

                <button id="theme-toggle" class="theme-toggle" aria-label="Toggle theme">🌙</button>
            </div>
        </nav>

        <section class="hero">
            <div class="left">
                <h1>Welcome to CampusCloud</h1>
                <p>Your student hub for schedules, resources, and campus updates — built simple and fast.</p>

                <div class="cta">
                    <a class="btn btn-primary" href="auth.php">Get Started</a>
                    <a class="btn btn-outline" href="#contact">Contact</a>
                </div>

                <div class="features">
                    <div class="card">
                        <strong>Easy access</strong>
                        <div class="card-desc">One place for course links and announcements.</div>
                    </div>

                    <div class="card">
                        <strong>Secure</strong>
                        <div class="card-desc">Built on simple authentication patterns you control.</div>
                    </div>

                    <div class="card">
                        <strong>Lightweight</strong>
                        <div class="card-desc">Fast pages and minimal dependencies.</div>
                    </div>
                </div>
            </div>

            <div class="right">
                <div class="card center-card">
                    <img src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='180' height='120'><rect rx='10' width='100%' height='100%' fill='%232b8df7'/><text x='50%' y='55%' font-size='18' text-anchor='middle' fill='white' font-family='Arial'>CampusCloud</text></svg>"
                        alt="CampusCloud" class="hero-img">

                    <div class="card-subtext">Start by creating an account or logging in.</div>
                </div>
            </div>
        </section>

        <!-- About -->
        <section id="about" class="section-spacing-lg">
            <div class="card">
                <h3>About CampusCloud</h3>
                <p class="muted-text">
                    CampusCloud is a lightweight student hub for schedules, announcements, and campus resources.
                    We focus on simple, fast access to the tools students and staff need.
                </p>
            </div>
        </section>

        <!-- Contact -->
        <section id="contact" class="section-spacing-md">
            <div class="card">
                <h3>Contact</h3>
                <p class="muted-text">
                    For support or feedback, email
                    <a href="mailto:support@example.edu">support@example.edu</a>
                    or visit the campus IT desk.
                </p>
            </div>
        </section>

        <footer>
            © <?php echo date('Y'); ?> CampusCloud — Built for your campus.
        </footer>

    </div>
</body>

</html>
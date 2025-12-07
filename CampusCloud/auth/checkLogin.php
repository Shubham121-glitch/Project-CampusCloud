<?php
include "../db/connection.php";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = trim($_POST['user_id']);
    $pin = trim($_POST['pin']);

    // Server-side validation
    if (!preg_match('/^\d{6}$/', $user_id)) {
        echo "<script>
                alert('Invalid User ID: must be exactly 6 digits');
                window.location.href = 'auth.php';
              </script>";
        exit;
    }

    if (!preg_match('/^\d{4}$/', $pin)) {
        echo "<script>
                alert('Invalid PIN: must be exactly 4 digits');
                window.location.href = 'auth.php';
              </script>";
        exit;
    }

    $user_id = intval($user_id);
    $pin = intval($pin);

    $query = mysqli_prepare($conn, "SELECT * FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($query, 'i', $user_id);
    mysqli_stmt_execute($query);
    $result = mysqli_stmt_get_result($query);
    $row = mysqli_fetch_assoc($result);
    if ($row) {
        if ($pin === intval($row['pin'])) {
            // successful login: start session and store user_id and username
            session_start();
            $_SESSION['user_id'] = $user_id;
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['user_id'] = $row['user_id'];
            echo "<script>
                    alert('Logged in successfully');
                    window.location.href = 'landing.php';
                  </script>";
            exit;
        } else {
            echo "<script>
                    alert('PIN not matched');
                    window.location.href = 'auth.php';
                  </script>";
            exit;
        }
    } else {
        echo "<script>
                alert('User not exists!');
                window.location.href = 'auth.php';
              </script>";
        exit;
    }
}
?>
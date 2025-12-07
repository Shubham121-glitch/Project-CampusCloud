<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — CampusCloud</title>
    <link rel="stylesheet" href="styles/login.css">
</head>
<body>
    <div class="container">
        <form action="checkLogin.php" method="post" onsubmit="return validateForm()">
            <h2>CampusCloud</h2>
            <input type="text" name="user_id" placeholder="User ID (6 digits)" inputmode="numeric" pattern="\d{6}" maxlength="6" required>
            <span class="error-hint" id="user-id-error"></span>
            <input type="text" name="pin" placeholder="PIN (4 digits)" inputmode="numeric" pattern="\d{4}" maxlength="4" required>
            <span class="error-hint" id="pin-error"></span>
            <button type="submit">Login</button>
        </form>
    </div>

    <script>
        function validateForm() {
            const userId = document.querySelector('input[name="user_id"]').value.trim();
            const pin = document.querySelector('input[name="pin"]').value.trim();
            const userIdError = document.getElementById('user-id-error');
            const pinError = document.getElementById('pin-error');

            // Clear previous errors
            userIdError.textContent = '';
            pinError.textContent = '';

            let isValid = true;

            // Validate user_id: must be exactly 6 digits
            if (!/^\d{6}$/.test(userId)) {
                userIdError.textContent = 'User ID must be exactly 6 digits';
                isValid = false;
            }

            // Validate pin: must be exactly 4 digits
            if (!/^\d{4}$/.test(pin)) {
                pinError.textContent = 'PIN must be exactly 4 digits';
                isValid = false;
            }

            if (!isValid) {
                return false;
            }

            return true;
        }

        // Real-time validation feedback
        document.querySelector('input[name="user_id"]').addEventListener('input', function() {
            const error = document.getElementById('user-id-error');
            if (this.value.length > 0 && !/^\d{6}$/.test(this.value)) {
                error.textContent = this.value.length < 6 ? 'Enter 6 digits' : 'Only digits allowed';
            } else {
                error.textContent = '';
            }
        });

        document.querySelector('input[name="pin"]').addEventListener('input', function() {
            const error = document.getElementById('pin-error');
            if (this.value.length > 0 && !/^\d{4}$/.test(this.value)) {
                error.textContent = this.value.length < 4 ? 'Enter 4 digits' : 'Only digits allowed';
            } else {
                error.textContent = '';
            }
        });
    </script>
</body>
</html>
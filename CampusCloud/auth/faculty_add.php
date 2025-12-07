<?php
include_once __DIR__ . '/require_role.php';
require_roles(['admin']);
$username = htmlspecialchars($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Faculty — CampusCloud</title>
  <link rel="stylesheet" href="styles/main.css">
  <link rel="stylesheet" href="styles/faculty.css">
  <script defer src="styles/theme.js"></script>
</head>
<body>
  <div class="faculty-container">
    <div class="faculty-card">
      <h1>Add New Faculty</h1>
      <p class="subtitle">Create a new faculty account with auto-generated credentials</p>

      <form id="facultyForm">
        <div class="form-group">
          <label for="name">Faculty Name *</label>
          <input type="text" id="name" name="name" placeholder="Enter full name" required>
          <span class="error" id="nameError"></span>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="user_id">User ID (6 digits) *</label>
            <div class="input-with-action">
              <input type="text" id="user_id" name="user_id" placeholder="Auto-generated" readonly>
              <button type="button" class="btn-regenerate" id="regenerateBtn" onclick="generateUserID()">🔄 Regenerate</button>
            </div>
            <span class="status" id="userIdStatus"></span>
            <span class="error" id="userIdError"></span>
          </div>

          <div class="form-group">
            <label for="pin">PIN (4 digits) *</label>
            <div class="input-with-action">
              <input type="text" id="pin" name="pin" placeholder="Auto-generated" readonly>
              <button type="button" class="btn-copy" id="copyPinBtn" onclick="copyToClipboard('pin')">📋 Copy</button>
            </div>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary" id="submitBtn">Create Faculty</button>
          <a href="faculty.php" class="btn btn-outline">Back</a>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Generate random 6-digit user_id
    function generateUserID() {
      const userId = String(Math.floor(100000 + Math.random() * 900000));
      document.getElementById('user_id').value = userId;
      checkUserIdAvailability(userId);
    }

    // Generate random 4-digit PIN
    function generatePin() {
      const pin = String(Math.floor(1000 + Math.random() * 9000));
      document.getElementById('pin').value = pin;
    }

    // Check if user_id already exists in database (AJAX)
    function checkUserIdAvailability(userId) {
      const statusEl = document.getElementById('userIdStatus');
      const errorEl = document.getElementById('userIdError');
      
      fetch('check_user_id.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'user_id=' + userId
      })
      .then(res => res.json())
      .then(data => {
        errorEl.textContent = '';
        if (data.exists) {
          statusEl.textContent = '❌ User ID already taken';
          statusEl.className = 'status error';
          document.getElementById('regenerateBtn').focus();
        } else {
          statusEl.textContent = '✓ User ID available';
          statusEl.className = 'status success';
        }
      })
      .catch(err => {
        statusEl.textContent = '⚠ Could not verify';
        statusEl.className = 'status warning';
      });
    }

    // Copy PIN to clipboard
    function copyToClipboard(elementId) {
      const text = document.getElementById(elementId).value;
      navigator.clipboard.writeText(text).then(() => {
        const btn = document.getElementById('copyPinBtn');
        const original = btn.textContent;
        btn.textContent = '✓ Copied!';
        setTimeout(() => { btn.textContent = original; }, 2000);
      });
    }

    // Form submission with confirmation
    document.getElementById('facultyForm').addEventListener('submit', function(e) {
      e.preventDefault();

      const name = document.getElementById('name').value.trim();
      const userId = document.getElementById('user_id').value.trim();
      const pin = document.getElementById('pin').value.trim();
      const nameError = document.getElementById('nameError');

      nameError.textContent = '';

      // Validate name
      if (!name || name.length < 2) {
        nameError.textContent = 'Name must be at least 2 characters';
        return;
      }

      // Validate user_id and pin are generated
      if (!userId || !pin) {
        alert('Please generate User ID and PIN first');
        return;
      }

      // Confirmation dialog
      const confirmMsg = `Create faculty account?\n\nName: ${name}\nUser ID: ${userId}\nPIN: ${pin}`;
      if (!confirm(confirmMsg)) {
        return;
      }

      // Submit to backend
      const formData = new FormData();
      formData.append('name', name);
      formData.append('user_id', userId);
      formData.append('pin', pin);

      fetch('create_faculty.php', {
        method: 'POST',
        body: formData
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          alert('Faculty created successfully!\n\nUser ID: ' + data.user_id + '\nPIN: ' + data.pin);
          window.location.href = 'faculty.php';
        } else {
          alert('Error: ' + (data.message || 'Could not create faculty'));
        }
      })
      .catch(err => {
        alert('Error: ' + err.message);
      });
    });

    // Generate credentials on page load
    window.addEventListener('DOMContentLoaded', function() {
      generateUserID();
      generatePin();
    });
  </script>
</body>
</html>

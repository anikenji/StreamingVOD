<?php
/**
 * Landing/Login Page
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body class="auth-page">
    <div class="auth-container">
        <div class="auth-box">
            <div class="logo-large">
                <h1>🎬 HLS Streaming</h1>
                <p>Professional Video Streaming Platform</p>
            </div>

            <!-- Login Form -->
            <div id="login-form" class="auth-form">
                <h2>Welcome Back</h2>
                <form onsubmit="handleLogin(event)">
                    <div class="form-group">
                        <label>Username or Email</label>
                        <input type="text" id="login-username" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" id="login-password" required>
                    </div>
                    <button type="submit" class="btn-primary btn-block">Login</button>
                </form>
            </div>

            <div class="alert" id="auth-alert" style="display: none;"></div>
        </div>
    </div>

    <script>
        function showRegister() {
            document.getElementById('login-form').style.display = 'none';
            document.getElementById('register-form').style.display = 'block';
        }

        function showLogin() {
            document.getElementById('register-form').style.display = 'none';
            document.getElementById('login-form').style.display = 'block';
        }

        function showAlert(message, type = 'error') {
            const alert = document.getElementById('auth-alert');
            alert.textContent = message;
            alert.className = `alert alert-${type}`;
            alert.style.display = 'block';
            setTimeout(() => { alert.style.display = 'none'; }, 5000);
        }

        async function handleLogin(e) {
            e.preventDefault();

            const username = document.getElementById('login-username').value;
            const password = document.getElementById('login-password').value;

            try {
                const response = await fetch('/api/auth/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });

                const data = await response.json();

                if (data.success) {
                    window.location.href = 'dashboard.php';
                } else {
                    showAlert(data.error || 'Login failed');
                }
            } catch (error) {
                showAlert('Network error. Please try again.');
            }
        }

        async function handleRegister(e) {
            e.preventDefault();

            const username = document.getElementById('register-username').value;
            const email = document.getElementById('register-email').value;
            const password = document.getElementById('register-password').value;

            try {
                const response = await fetch('/api/auth/register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, email, password })
                });

                const data = await response.json();

                if (data.success) {
                    showAlert('Registration successful! Please login.', 'success');
                    setTimeout(() => showLogin(), 2000);
                } else {
                    showAlert(data.error || 'Registration failed');
                }
            } catch (error) {
                showAlert('Network error. Please try again.');
            }
        }
    </script>
</body>

</html>
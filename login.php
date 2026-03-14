<?php
require_once 'db_config.php';
require_once 'jwt_helper.php';

// Check if already authenticated via token
if (isset($_COOKIE['jwt_token'])) {
    $jwt_token = $_COOKIE['jwt_token'];
    $decoded_payload = verify_jwt($jwt_token);

    if ($decoded_payload !== false) {
        $level = $decoded_payload['user_level'];
        switch ($level) {
            case 'admin':
                header("Location: admin_dashboard.php");
                break;
            case 'agency':
                header("Location: agency_dashboard.php");
                break;
            case 'client':
                header("Location: client_dashboard.php");
                break;
            case 'guard':
                header("Location: guard_dashboard.php");
                break;
            default:
                // Optionally handle unknown user levels or do nothing
                break;
        }
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sentinel Tour - Login</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@500;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-dark: #0a192f;
            --brand-dark-accent: #112240;
            --brand-green: #0eb06b;
            --brand-green-hover: #0c965b;
            --brand-green-light: rgba(14, 176, 107, 0.1);
            --bg-light: #f8fafc;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --white: #ffffff;
            --error: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            overflow: hidden;
        }

        /* Split Screen Layout */
        .split-layout {
            display: flex;
            width: 100%;
            height: 100vh;
        }

        /* Left Side - Branding Image */
        .hero-section {
            flex: 1.2;
            background-color: #ffffff;
            position: relative;
            display: flex;
            flex-direction: column; /* Stack vertically */
            justify-content: center;
            align-items: center;
            padding: 3rem;
            box-shadow: 10px 0 30px rgba(0,0,0,0.05);
            z-index: 1;
        }

        .hero-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 100%;
            max-width: 600px;
        }

        .hero-logo-container {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-bottom: 0px; /* Bring text close */
        }

        /* Logo Image Styling */
        .brand-logo {
            max-width: 90%;
            width: auto;
            height: auto;
            max-height: 500px; /* Reduced from 400px so it's not cut off */
            object-fit: contain;
        }



        /* Right Side - Login Form */
        .form-section {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: #f1f5f9; /* Slate 100 - subtly different from white to contrast */
            /* Or if you prefer a noticeable blue tint: background-color: #e0f2fe; (Sky 100) */
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 2rem;
            position: relative;
        }

        .login-card {
            width: 100%;
            max-width: 440px;
            padding: 3rem;
            background: var(--white);
            border-radius: 1.5rem;
            box-shadow: 0 20px 40px rgba(0,0,0,0.05);
            animation: fade-in 1s cubic-bezier(0.16, 1, 0.3, 1) 0.2s forwards;
            opacity: 0;
        }

        .mobile-logo {
            display: none;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .mobile-logo img {
            max-width: 100%;
            height: auto;
            max-height: 180px; /* Enlarged for mobile too */
            object-fit: contain;
        }
        


        .form-header {
            margin-bottom: 2.5rem;
        }

        .form-header h2 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--brand-dark);
            margin-bottom: 0.5rem;
            font-family: 'Outfit', sans-serif;
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 0.5rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper svg {
            position: absolute;
            left: 1rem;
            width: 1.25rem;
            height: 1.25rem;
            color: #94a3b8;
            transition: color 0.3s ease;
            pointer-events: none;
        }

        .form-group input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 3rem;
            background-color: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            font-size: 1rem;
            color: var(--text-dark);
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
        }

        .form-group input::placeholder {
            color: #94a3b8;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--brand-green);
            background-color: var(--white);
            box-shadow: 0 0 0 4px var(--brand-green-light);
        }

        .form-group input:focus + svg,
        .form-group input:not(:placeholder-shown) + svg {
            color: var(--brand-green);
        }

        .submit-btn {
            width: 100%;
            padding: 1rem;
            background-color: var(--brand-dark);
            color: var(--white);
            border: none;
            border-radius: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
            position: relative;
            overflow: hidden;
        }

        .submit-btn:hover {
            background-color: var(--brand-green);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(14, 176, 107, 0.2);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn svg {
            width: 1.25rem;
            height: 1.25rem;
            transition: transform 0.3s ease;
        }

        .submit-btn:hover svg {
            transform: translateX(4px);
        }

        @keyframes fade-in-up {
            0% { opacity: 0; transform: translateY(30px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        @keyframes fade-in {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }

        /* Responsive Design */
        @media (max-width: 900px) {
            .hero-section {
                display: none;
            }
            .form-section {
            /* Fallback to simple bg on mobile if needed */
                background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            }
            .mobile-logo {
                display: block;
            }
            .login-card {
                padding: 2.5rem 2rem;
                box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            }
            .submit-btn {
                background-color: var(--brand-green);
            }
            .submit-btn:hover {
                background-color: var(--brand-green-hover);
            }
        }
    </style>
    <script>
        // Prevent going back to the login page via browser back button after successful login/logout
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Clear inputs on page load to prevent browser autofill issues visually
        window.addEventListener('DOMContentLoaded', (event) => {
            const usernameField = document.getElementById('username');
            const passwordField = document.getElementById('password');
            if (usernameField && !usernameField.value) usernameField.value = '';
            if (passwordField && !passwordField.value) passwordField.value = '';
        });
    </script>
</head>
<body>
    <div class="split-layout">
        <!-- Left Side: Custom Layout for Logo and Text -->
        <div class="hero-section">
            <div class="hero-content">
                <div class="hero-logo-container">
                    <img src="assets/logo.png" alt="Sentinel Tour Logo" class="brand-logo">
                </div>
            </div>
        </div>

        <!-- Right Side -->
        <div class="form-section">
            <div class="login-card">
                
                <div class="mobile-logo">
                    <img src="assets/logo.png" alt="Sentinel Tour Logo">
                </div>

                <div class="form-header">
                    <h2>Welcome</h2>
                    <p>Please enter your details to login</p>
                </div>

                <form action="login_action.php" method="POST" autocomplete="off">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <input type="text" id="username" name="username" required placeholder="Enter your username" autocomplete="off">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="password" name="password" required placeholder="Enter password" autocomplete="new-password">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="loginBtn">
                        Sign In
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>

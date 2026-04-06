<?php
session_start();
include 'db_config.php';
include 'jwt_helper.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user_agent = $conn->real_escape_string($_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN');

    $login_type = $_POST['login_type'] ?? 'staff';

    $sql = "SELECT * FROM users WHERE username = '$username'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Check unauthorized roles
        $allowed_roles = ['admin', 'agency', 'client'];
        if (!in_array($user['user_level'], $allowed_roles)) {
            echo "<script>alert('Only management roles can access this portal.'); window.location.href='login.php';</script>";
            exit();
        }

        // Check Account Status
        if (($user['status'] ?? 'active') === 'suspended') {
            echo "<script>alert('Your account has been suspended. Please contact the administrator.'); window.location.href='login.php';</script>";
            exit();
        }

        if (password_verify($password, $user['password'])) {
            // Log Success
            $conn->query("INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('$username', '$ip_address', '$user_agent', 'SUCCESS')");
            
            // Generate JWT Token
            $payload = [
                'user_id' => $user['id'],
                'username' => $user['username'],
                'user_level' => $user['user_level']
            ];
            $token = generate_jwt($payload);
            
            // Set cookie for 1 hour (HttpOnly = true)
            setcookie('jwt_token', $token, time() + 3600, '/', '', isset($_SERVER["HTTPS"]), true);

            // Keep session for legacy support temporarily
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_level'] = $user['user_level'];

            // Detect user level and redirect
            switch ($user['user_level']) {
                case 'admin':
                    header("Location: admin_dashboard.php");
                    break;
                case 'agency':
                    header("Location: agency_dashboard.php");
                    break;
                case 'client':
                    header("Location: client_dashboard.php");
                    break;
                default:
                    echo "<script>alert('Invalid user level!'); window.location.href='login.php';</script>";
                    break;
            }
            exit();
        } else {
            // Log Failure
            $conn->query("INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('$username', '$ip_address', '$user_agent', 'FAILED')");
            echo "<script>alert('Incorrect password!'); window.location.href='login.php';</script>";
        }
    } else {
        // Log Failure
        $conn->query("INSERT INTO login_logs (username, ip_address, user_agent, status) VALUES ('$username', '$ip_address', '$user_agent', 'FAILED')");
        echo "<script>alert('Username not found!'); window.location.href='login.php';</script>";
    }
}
$conn->close();
?>

<?php
session_start();
include 'db_config.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $conn->real_escape_string($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $user_level = $_POST['user_level'];

    // Basic validation
    if (!in_array($user_level, ['agency', 'client'])) {
        die("Invalid user level selected.");
    }

    // Check if username exists
    $checkSql = "SELECT id FROM users WHERE username = '$username'";
    $result = $conn->query($checkSql);

    if ($result->num_rows > 0) {
        $_SESSION['auth_error'] = 'Username already exists!';
        header("Location: register.php");
        exit();
    } else {
        $sql = "INSERT INTO users (username, password, user_level) VALUES ('$username', '$password', '$user_level')";
        
        if ($conn->query($sql) === TRUE) {
            $_SESSION['auth_success'] = 'Registration successful! You can now login.';
            header("Location: login.php");
            exit();
        } else {
            echo "Error: " . $sql . "<br>" . $conn->error;
        }
    }
}
$conn->close();
?>

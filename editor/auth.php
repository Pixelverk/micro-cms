<?php
session_start();

// Hardcoded users for now
$users = [
    'admin' => 'password123',
    'editor' => 'editorpass'
];

$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if (isset($users[$username]) && $users[$username] === $password) {
    // Successful login
    $_SESSION['logged_in'] = true;
    $_SESSION['username'] = $username;
    header("Location: index.php");
    exit;
} else {
    // Failed login
    header("Location: login.php?error=Invalid+username+or+password");
    exit;
}

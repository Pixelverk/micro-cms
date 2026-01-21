<?php

// --------------------------------------------
// Read input
// --------------------------------------------
$action = $_POST['action'] ?? '';
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';
$firstName = trim($_POST['first_name'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');

// --------------------------------------------
// Basic validation
// --------------------------------------------
if ($username === '') {
    redirect_with_toast('user-add', 'error', 'Username is required.');
}

// Normalize username
$username = strtolower($username);

// Allow only safe usernames
if (!preg_match('/^[a-z0-9_-]+$/', $username)) {
    redirect_with_toast('user-add', 'error', 'Username may only contain lowercase letters, numbers, dashes and underscores.');
}

// Validate email if provided
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $redirect = $action === 'create' ? 'user-add' : 'user-edit';
    redirect_with_toast($redirect, 'error', 'Invalid email address.', ['username' => $username]);
}

// --------------------------------------------
// CREATE USER
// --------------------------------------------
if ($action === 'create') {

    if ($password === '' || $passwordConfirm === '') {
        redirect_with_toast('user-add', 'error', 'Password is required.');
    }

    if ($password !== $passwordConfirm) {
        redirect_with_toast('user-add', 'error', 'Passwords do not match.');
    }

    if (user_exists($username)) {
        redirect_with_toast('user-add', 'error', 'User already exists.');
    }

    create_user($username, $password, $firstName, $lastName, $email);

    redirect_with_toast('user-list', 'success', "User \"$username\" created successfully.");
}

// --------------------------------------------
// UPDATE USER
// --------------------------------------------
if ($action === 'update') {

    $user = current_user();
    if (!$user) {
        redirect_with_toast('user-list', 'error', 'User not found.');
    }

    // Load the target user
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        redirect_with_toast('user-list', 'error', 'User not found.');
    }

    $updateData = [
        'id' => $targetUser['id'],
        'username' => $username,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password_hash' => $targetUser['password_hash'], // keep current password by default
        'last_login' => $targetUser['last_login'] ?? null,
    ];

    // Update password if provided
    if ($password !== '' || $passwordConfirm !== '') {
        if ($password !== $passwordConfirm) {
            redirect_with_toast('user-edit', 'error', 'Passwords do not match.', ['username' => $username]);
        }
        $updateData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    save_user($updateData);

    redirect_with_toast('user-list', 'success', "User \"$username\" updated successfully.");
}

// --------------------------------------------
// Unknown action
// --------------------------------------------
redirect_with_toast('user-list', 'error', 'Invalid action.');
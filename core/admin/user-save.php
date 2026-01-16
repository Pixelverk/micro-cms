<?php

// --------------------------------------------
// Read input
// --------------------------------------------
$action = $_POST['action'] ?? '';
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirm'] ?? '';

// --------------------------------------------
// Basic validation
// --------------------------------------------
if ($username === '') {
    redirect_with_toast('user-add', 'error', 'Username is required.');
}

// Normalize username (important)
$username = strtolower($username);

// Allow only safe usernames
if (!preg_match('/^[a-z0-9_-]+$/', $username)) {
    redirect_with_toast('user-add', 'error', 'Username may only contain lowercase letters, numbers, dashes and underscores.');
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

    create_user($username, $password);

    redirect_with_toast('user-list','success', "User \"$username\" created successfully.");
}

// --------------------------------------------
// UPDATE USER
// --------------------------------------------
if ($action === 'update') {

    $users = load_users();

    if (!isset($users[$username])) {
        redirect_with_toast('user-list', 'error', 'User not found.');
    }

    // If password fields are empty → keep existing password
    if ($password !== '' || $passwordConfirm !== '') {
        if ($password !== $passwordConfirm) {
            redirect_with_toast(
                'user-edit',        
                'error',           
                'Passwords do not match.',
                [
                    'username' => $username
                ]
            );
        }

        $users[$username]['password'] = password_hash(
            $password,
            PASSWORD_DEFAULT
        );
    }

    save_users($users);

    redirect_with_toast('user-list', 'success', "User \"$username\" updated successfully."
    );
}

// --------------------------------------------
// Unknown action
// --------------------------------------------
redirect_with_toast('user-list', 'error', 'Invalid action.');
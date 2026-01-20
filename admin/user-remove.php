<?php

// ----------------------------
// Get username from GET or POST
// ----------------------------
$username = $_GET['username'] ?? $_POST['username'] ?? '';
if (!$username) {
    redirect_with_toast('user-list', 'error', 'Missing username.');
}

// Normalize username
$username = strtolower($username);

// Prevent removing yourself
if ($username === current_user()) {
    redirect_with_toast('user-list', 'error', 'You cannot remove your own account.');
}

// Load existing users
$users = load_users();

// Check user exists
if (!isset($users[$username])) {
    redirect_with_toast('user-list', 'error', 'User not found.');
}

// Attempt to remove user
unset($users[$username]);

// Save updated user list
save_users($users);

// Success
redirect_with_toast('user-list', 'success', "User \"$username\" removed successfully.");

<?php
declare(strict_types=1);

$pdo = db();

// ----------------------------
// Get username from GET or POST
// ----------------------------
$username = trim($_GET['username'] ?? $_POST['username'] ?? '');
if ($username === '') {
    redirect_with_toast('user', 'error', 'Missing username.');
}

// Normalize username
$username = strtolower($username);

// ----------------------------
// Prevent removing yourself
// ----------------------------
$currentUser = current_user();
if ($currentUser && $username === $currentUser['username']) {
    redirect_with_toast('user', 'error', 'You cannot remove your own account.');
}

// ----------------------------
// Check if user exists
// ----------------------------
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
$stmt->execute(['username' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    redirect_with_toast('user', 'error', 'User not found.');
}

// ----------------------------
// Delete user
// ----------------------------
$stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);

// ----------------------------
// Success
// ----------------------------
redirect_with_toast('user', 'success', "User \"$username\" removed successfully.");
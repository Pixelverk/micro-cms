<?php
declare(strict_types=1);

$pdo = db();

// ----------------------------
// POST only (destructive action)
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

// ----------------------------
// Get username from the form
// ----------------------------
$username = trim($_POST['username'] ?? '');
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
// Refuse to delete the only remaining administrator
// ----------------------------
if (admin_is_last_admin((int) $user['id'])) {
    redirect_with_toast('user', 'error', 'This is the last administrator and cannot be removed.');
}

// ----------------------------
// Delete user
// ----------------------------
$stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
$stmt->execute(['id' => $user['id']]);

// ----------------------------
// Success
// ----------------------------
log_activity('user.deleted', 'user', (int) $user['id'], $username, []);

redirect_with_toast('user', 'success', "User \"$username\" removed successfully.");
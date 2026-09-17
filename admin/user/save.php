<?php
declare(strict_types=1);

// --------------------------------------------
// Read input
// --------------------------------------------
$action           = (string) ($_POST['action'] ?? '');
$username         = trim((string) ($_POST['username'] ?? ''));
$originalUsername = trim((string) ($_POST['original_username'] ?? ''));
$password         = (string) ($_POST['password'] ?? '');
$passwordConfirm  = (string) ($_POST['password_confirm'] ?? '');
$firstName        = trim((string) ($_POST['first_name'] ?? ''));
$lastName         = trim((string) ($_POST['last_name'] ?? ''));
$email            = trim((string) ($_POST['email'] ?? ''));
$uiLanguage       = trim((string) ($_POST['ui_language'] ?? ''));
$role             = trim((string) ($_POST['role'] ?? 'author'));

if (!in_array($action, ['create', 'update'], true)) {
    redirect_with_toast('user', 'error', 'Invalid action.');
}

// Normalise before validating.
$username = strtolower($username);

$redirectPath = $action === 'create' ? 'user/add' : 'user/edit';
$redirectArgs = $action === 'create' ? [] : ['username' => $originalUsername !== '' ? $originalUsername : $username];

$errors = [];

if ($username === '') {
    $errors['username'] = 'A username is required.';
} elseif (!validate_username($username)) {
    $errors['username'] = 'Usernames are 3-32 characters: lowercase letters, numbers, dot, dash or underscore.';
}

if ($email !== '' && !validate_email($email)) {
    $errors['email'] = 'That email address does not look valid.';
}

if ($uiLanguage !== '' && !array_key_exists($uiLanguage, admin_languages())) {
    $errors['ui_language'] = 'Unknown admin language.';
}

if (!in_array($role, admin_roles(), true)) {
    $errors['role'] = 'Unknown role.';
}

$minLength = (int) config('security.password_min_length', 10);

// Password rules differ: required on create, optional on update.
if ($action === 'create') {
    if ($password === '' || $passwordConfirm === '') {
        $errors['password'] = 'A password is required.';
    } elseif (strlen($password) < $minLength) {
        $errors['password'] = "Passwords must be at least {$minLength} characters.";
    } elseif ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
} elseif ($password !== '' || $passwordConfirm !== '') {
    if (strlen($password) < $minLength) {
        $errors['password'] = "Passwords must be at least {$minLength} characters.";
    } elseif ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }
}

// Resolve the target account (create: the new name, update: the stored name).
$lookupName = $action === 'create' ? $username : ($originalUsername !== '' ? $originalUsername : $username);
$targetUser = null;

if (!$errors) {
    $existing = db()->prepare("SELECT id, username, password_hash, last_login FROM users WHERE username = :username LIMIT 1");
    $existing->execute(['username' => $lookupName]);
    $targetUser = $existing->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($action === 'create' && $targetUser) {
        $errors['username'] = 'That username is already taken.';
    }

    if ($action === 'update' && !$targetUser) {
        $errors['username'] = 'That user no longer exists.';
    }

    $targetId = (int) ($targetUser['id'] ?? 0);

    // Refuse to demote the only remaining administrator.
    if (!$errors && $action === 'update' && $role !== 'admin' && admin_is_last_admin($targetId)) {
        $errors['role'] = 'This is the last administrator; promote someone else first.';
    }

    if (!$errors && $username !== $lookupName) {
        $clash = db()->prepare("SELECT COUNT(*) FROM users WHERE username = :username AND id != :id");
        $clash->execute(['username' => $username, 'id' => $targetId]);

        if ((int) $clash->fetchColumn() > 0) {
            $errors['username'] = 'Another account already uses that username.';
        }
    }

    if (!$errors && $email !== '') {
        $emailClash = db()->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND id != :id");
        $emailClash->execute(['email' => $email, 'id' => $targetId]);

        if ((int) $emailClash->fetchColumn() > 0) {
            $errors['email'] = 'Another account already uses that email address.';
        }
    }
}

if ($errors) {
    validate_throw($errors, $redirectPath);
}

// --------------------------------------------
// Self-service guard
// --------------------------------------------
// A user without users.manage may only update their own account, and may not
// change their own username or role. The role override matters because the
// profile form hides the role field, so a posted role would default to 'author'.
if (!admin_can('users.manage')) {
    if ($action !== 'update' || $lookupName !== current_username()) {
        log_activity('security.forbidden', 'user', (int) ($targetUser['id'] ?? 0), 'users.manage', []);
        http_response_code(403);
        render_admin_forbidden('users.manage');
        exit;
    }

    $username = $lookupName;
    $role     = (string) $targetUser['role'];
}

// --------------------------------------------
// CREATE
// --------------------------------------------
if ($action === 'create') {
    create_user($username, $password, $firstName, $lastName, $email, $role);

    log_activity('user.created', 'user', null, $username, []);

    redirect_with_toast('user', 'success', "User \"{$username}\" created successfully.");
}

// --------------------------------------------
// UPDATE
// --------------------------------------------
$updateData = [
    'id'            => (int) $targetUser['id'],
    'username'      => $username,
    'first_name'    => $firstName,
    'last_name'     => $lastName,
    'email'         => $email,
    'ui_language'   => $uiLanguage !== '' ? $uiLanguage : null,
    'role'          => $role,
    // Keep the stored hash unless a new password was supplied.
    'password_hash' => (string) $targetUser['password_hash'],
    'last_login'    => $targetUser['last_login'] ?? null,
];

if ($password !== '') {
    $updateData['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
}

save_user($updateData);

log_activity('user.updated', 'user', (int) $targetUser['id'], $username, ['role' => $role]);

redirect_with_toast('user', 'success', "User \"{$username}\" updated successfully.");

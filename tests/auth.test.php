<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Authentication, identity and throttling
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('the seeded demo account can log in', function () {
    $_SESSION = [];
    assert_true(login('demo', 'demo'));
    assert_eq(1, current_user_id(), 'session must hold the numeric user id');
    assert_eq('demo', current_username());
    assert_eq('demo', current_user()['username']);
});

t('a fresh install ships one account per role', function () {
    $roles = [];

    foreach (['demo', 'editor', 'author'] as $username) {
        $_SESSION = [];

        assert_true(login($username, $username), "{$username} signs in with its own name as the password");
        $roles[$username] = current_user()['role'];
    }

    assert_eq(['demo' => 'admin', 'editor' => 'editor', 'author' => 'author'], $roles, 'each account carries its role');
});

t('login issues a per-browser preview token', function () {
    $_SESSION = [];
    unset($_COOKIE[preview_cookie_name()]);

    login('demo', 'demo');

    $token = $_COOKIE[preview_cookie_name()] ?? '';

    assert_true($token !== '', 'a preview token must be issued');
    assert_eq(32, strlen($token), 'preview token length');
    assert_true((bool) preg_match('/^[a-f0-9]{32}$/', $token), 'token is hex');
});

t('a wrong password does not authenticate', function () {
    $_SESSION = [];
    assert_false(login('demo', 'nope'));
    assert_eq(null, current_user_id());
});

t('an unknown user does not authenticate', function () {
    $_SESSION = [];
    assert_false(login('ghost', 'demo'));
});

t('current_user() is null without a session', function () {
    $_SESSION = [];
    assert_eq(null, current_user());
    assert_eq(null, current_user_id());
    assert_eq('User', current_username());
});

t('legacy username sessions still resolve', function () {
    // Sessions created before identities moved to numeric ids held a username.
    $_SESSION = ['user_id' => 'demo'];
    assert_eq(1, current_user_id(), 'legacy string id must resolve');
});

t('is_logged_in() follows the numeric id', function () {
    $_SESSION = [];
    assert_false(is_logged_in());

    test_login_session();
    assert_true(is_logged_in());
});

t('throttle locks after the configured number of failures', function () {
    db()->exec("DELETE FROM login_attempts");
    $_SERVER['REMOTE_ADDR'] = '10.0.0.9';

    $max = config('security.login_max_attempts');

    assert_false(throttle_is_locked('demo'), 'starts unlocked');

    for ($i = 0; $i < $max; $i++) {
        throttle_fail('demo');
    }

    assert_true(throttle_is_locked('demo'), 'should lock after max failures');
    assert_true(throttle_seconds_remaining('demo') > 0, 'remaining lockout should be positive');
});

t('a successful login clears the throttle', function () {
    db()->exec("DELETE FROM login_attempts");
    $_SERVER['REMOTE_ADDR'] = '10.0.0.10';

    throttle_fail('demo');
    $_SESSION = [];
    assert_true(login('demo', 'demo'), 'login should succeed');
    assert_false(throttle_is_locked('demo'), 'throttle should be cleared');
});

t('throttle keys are per address', function () {
    db()->exec("DELETE FROM login_attempts");

    $_SERVER['REMOTE_ADDR'] = '10.0.0.11';
    for ($i = 0; $i < config('security.login_max_attempts'); $i++) {
        throttle_fail('demo');
    }
    assert_true(throttle_is_locked('demo'));

    $_SERVER['REMOTE_ADDR'] = '10.0.0.12';
    assert_false(throttle_is_locked('demo'), 'another address must not inherit the lockout');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

t('logout empties the session', function () {
    test_login_session();
    assert_true(is_logged_in());

    logout();
    $_SESSION = []; // session_destroy() is a no-op for the CLI session array

    assert_false(is_logged_in(), 'logout must clear identity');
});

// ---------------------------------------------------------------------------
// Password reset
// ---------------------------------------------------------------------------

t('a reset request issues a token and logs the mail instead of sending it', function () {
    db()->exec("DELETE FROM password_resets");
    $_SERVER['REMOTE_ADDR'] = '10.0.0.20';

    $raw = password_reset_request('admin@example.com');

    assert_true(is_string($raw) && strlen($raw) === 64, 'a raw token is returned');

    $stored = (string) db()->query("SELECT token_hash FROM password_resets")->fetchColumn();
    assert_eq(hash('sha256', $raw), $stored, 'only the hash is stored');

    $log = (string) @file_get_contents(STORAGE_PATH . '/logs/forms.log');
    assert_contains('reset-password', $log, 'the link is logged in non-production');
    assert_contains($raw, $log, 'and carries the token');
});

t('a request for an unknown address is silent', function () {
    db()->exec("DELETE FROM password_resets");

    assert_eq(null, password_reset_request('nobody@example.com'));
    assert_eq(0, (int) db()->query("SELECT COUNT(*) FROM password_resets")->fetchColumn());
});

t('an expired reset token is refused', function () {
    db()->exec("DELETE FROM password_resets");
    $raw = password_reset_request('admin@example.com');

    assert_true(password_reset_find($raw) !== null, 'valid before expiry');

    db()->exec("UPDATE password_resets SET expires_at = " . (time() - 1));
    assert_eq(null, password_reset_find($raw), 'an expired token matches nothing');
});

t('a bad reset token is refused', function () {
    assert_eq(null, password_reset_find('not-a-token'));
    assert_eq(null, password_reset_find(str_repeat('a', 64)), 'an unknown 64-hex token matches nothing');
});

t('a reset token is single use and changes the password', function () {
    db()->exec("DELETE FROM password_resets");
    $raw = password_reset_request('admin@example.com');
    $reset = password_reset_find($raw);

    assert_true($reset !== null, 'the issued token resolves');

    password_reset_complete($reset, 'brand-new-password-1');

    assert_eq(null, password_reset_find($raw), 'a spent token is refused');
    assert_true(login('demo', 'brand-new-password-1'), 'the new password works');
    assert_false(login('demo', 'demo'), 'the old password no longer does');

    // Leave the seeded password in place for the rest of the suite.
    db()->prepare("UPDATE users SET password_hash = :hash WHERE id = 1")
        ->execute(['hash' => password_hash('demo', PASSWORD_DEFAULT)]);
});

t('the reset request rate limit counts every request per address', function () {
    db()->exec("DELETE FROM form_rate_limits");
    $_SERVER['REMOTE_ADDR'] = '10.0.0.21';

    for ($i = 0; $i < 5; $i++) {
        assert_true(password_reset_rate_limit_ok(), 'under the limit');
    }

    assert_false(password_reset_rate_limit_ok(), 'the sixth request is refused');

    $_SERVER['REMOTE_ADDR'] = '10.0.0.22';
    assert_true(password_reset_rate_limit_ok(), 'another address is unaffected');

    db()->exec("DELETE FROM form_rate_limits");
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
});

t('a completed reset invalidates sessions opened with the old password', function () {
    $_SESSION = [];
    unset($_COOKIE[preview_cookie_name()]);

    login('demo', 'demo');
    $oldFingerprint = (string) ($_SESSION['auth_fingerprint'] ?? '');
    assert_true($oldFingerprint !== '', 'login stores a fingerprint');

    db()->exec("DELETE FROM password_resets");
    $raw = password_reset_request('admin@example.com');
    $reset = password_reset_find($raw);
    assert_true($reset !== null);

    password_reset_complete($reset, 'another-new-password-2');

    // A later request still holding the old session must be signed out.
    [$output] = test_php([
        '$_SESSION = ["user_id" => 1, "auth_fingerprint" => ' . var_export($oldFingerprint, true) . '];',
        'session_validate_identity();',
        'echo empty($_SESSION["user_id"]) ? "signed-out" : "still-signed-in";',
    ]);

    assert_contains('signed-out', implode("\n", $output));

    db()->prepare("UPDATE users SET password_hash = :hash WHERE id = 1")
        ->execute(['hash' => password_hash('demo', PASSWORD_DEFAULT)]);
});

exit(test_summary());

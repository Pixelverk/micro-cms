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

exit(test_summary());

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CSRF helpers
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

t('csrf_token() is stable within a session and 64 hex chars', function () {
    $_SESSION = [];
    $first = csrf_token();
    $second = csrf_token();

    assert_eq($first, $second, 'token should not rotate per call');
    assert_eq(64, strlen($first), 'token length');
    assert_true(ctype_xdigit($first), 'token must be hex');
});

t('csrf_check() accepts only the session token', function () {
    $_SESSION = ['csrf_token' => 'aaaabbbb'];
    $_POST = ['_token' => 'aaaabbbb'];
    assert_true(csrf_check());

    $_POST = ['_token' => 'aaaabbbc'];
    assert_false(csrf_check(), 'near-miss token must fail');

    $_POST = [];
    assert_false(csrf_check(), 'missing token must fail');

    unset($_SESSION['csrf_token']);
    $_POST = ['_token' => 'aaaabbbb'];
    assert_false(csrf_check(), 'missing session token must fail');
});

t('csrf_field() emits a hidden input', function () {
    $_SESSION = [];
    $html = csrf_field();

    assert_contains('name="_token"', $html);
    assert_contains(e(csrf_token()), $html);
});

t('form tokens accept the current and previous time bucket only', function () {
    $token = form_token('contact', 7200);

    assert_true(form_token_check('contact', $token), 'current token must pass');
    assert_false(form_token_check('newsletter', $token), 'token must be bound to the form type');
    assert_false(form_token_check('contact', 'not-a-token'), 'junk must fail');
    assert_false(form_token_check('contact', ''), 'empty must fail');
    assert_false(form_token_check('contact', null), 'null must fail');

    // Forged bucket with a valid-looking MAC
    [$bucket] = explode('.', $token, 2);
    assert_false(form_token_check('contact', ((int) $bucket + 5) . '.' . str_repeat('a', 32)), 'future bucket must fail');
});

t('form_token_field() carries the signed token', function () {
    $html = form_token_field('contact');

    assert_contains('name="_form_token"', $html);
    assert_contains(form_token('contact'), $html);
});

t('csrf_assert() aborts JSON callers with 419', function () {
    $_SESSION = ['csrf_token' => 'expected'];
    $_POST = ['_token' => 'wrong'];
    $_SERVER['HTTP_ACCEPT'] = 'application/json';

    // csrf_assert() calls exit, so run it in an isolated process.
    [$output] = test_php([
        '$_SESSION = ["csrf_token" => "expected"];',
        '$_POST = ["_token" => "wrong"];',
        '$_SERVER["HTTP_ACCEPT"] = "application/json";',
        'csrf_assert();',
        'echo "REACHED";',
    ]);

    $text = implode("\n", $output);
    assert_not_contains('REACHED', $text, 'must exit before continuing');
    assert_contains('security token', $text, 'should explain the failure');
});

t('csrf_assert() lets a valid token through', function () {
    $_SESSION = ['csrf_token' => 'expected'];
    $_POST = ['_token' => 'expected'];

    ob_start();
    csrf_assert();
    $output = ob_get_clean();

    assert_eq('', $output, 'a valid token must not emit anything');
});

exit(test_summary());

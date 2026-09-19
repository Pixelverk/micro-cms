# Tests

A tiny, dependency-free test harness — no composer, no PHPUnit, consistent with
the rest of this project.

## Run everything

```bash
php tests/run.php
```

## Run one suite

```bash
php tests/run.php csrf
php tests/run.php http
```

## What each suite covers

| File | Covers |
| --- | --- |
| `csrf.test.php` | CSRF token generation, comparison, and the signed time-boxed tokens used by cached public forms |
| `auth.test.php` | Login/logout, numeric identity, legacy sessions, login throttling |
| `admin.test.php` | Admin capability hook (`admin_guard`/`admin_can`), HTML minification, cache invalidation |
| `http.test.php` | End-to-end behaviour through `index.php` on a real PHP server: routing, sessions, CSRF enforcement, draft visibility, form submission |

## How isolation works

* Every test file runs in its own PHP process, so module-level statics (database
  handle, config, settings, current user) cannot leak between files.
* All artefacts live in `tests/.tmp/` (gitignored). A test run **never** touches
  `storage/` — the bootstrap refuses to start if the test storage resolves inside
  the real storage directory.
* `tests/run.php` holds an exclusive lock on `tests/.tmp/run.lock` for the whole
  run. Every suite shares `tests/.tmp/storage` and replaces `data.sqlite` before
  it starts, so two concurrent runs would delete the database out from under each
  other (the symptom is spurious `database is locked` / `attempt to write a
  readonly database` failures). A second run waits its turn instead.
* `tests/config.test.php` points `storage_path` at `tests/.tmp/storage` and
  `tests/bootstrap.php` puts everything else in place. `CMS_CONFIG_FILE` and the
  optional `storage_path` config key are the only production hooks this needs.

## Running the site locally with the built-in server

`.htaccess` is not used by `php -S`, so use the router script:

```bash
CMS_CONFIG_FILE="$PWD/tests/config.server.php" \
  php -S 127.0.0.1:8080 tests/router.php
```

`tests/router.php` mirrors the Apache rules: `theme/assets/` and `admin/assets/`
are served directly, `core/` and `storage/` are blocked, everything else goes
through `index.php`.

## Adding a test

Create `tests/<name>.test.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
test_fresh_database(); // optional: start from the seeded demo site

t('something is true', function () {
    assert_true(1 + 1 === 2);
});

exit(test_summary());
```

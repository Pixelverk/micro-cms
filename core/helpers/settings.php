<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Settings cache
|--------------------------------------------------------------------------
| Settings are read many times per request but written rarely. Both readers
| below memoise into a shared array so a write can invalidate everything.
*/

$GLOBALS['cms_settings_cache'] = ['loaded' => false, 'values' => [], 'single' => false];

function settings_cache_clear(): void
{
    $GLOBALS['cms_settings_cache'] = ['loaded' => false, 'values' => [], 'single' => false];
}

/*
|--------------------------------------------------------------------------
| Load All Settings
|--------------------------------------------------------------------------
*/

/**
 * Every setting as key => value, fetched in one query and memoised for the
 * rest of the request (this used to be one query per key, several times over).
 */
function load_settings(bool $refresh = false): array
{
    if ($refresh) {
        settings_cache_clear();
    }

    if ($GLOBALS['cms_settings_cache']['loaded']) {
        return $GLOBALS['cms_settings_cache']['values'];
    }

    $pdo = db();

    $settings = [];
    foreach ($pdo->query("SELECT `key`, `value` FROM settings") as $row) {
        $settings[$row['key']] = decode_setting_value($row['value']);
    }

    // Convenience: which page is the homepage.
    if (!empty($settings['homepage_id'])) {
        $page = load_content_by_id((int) $settings['homepage_id']);
        $settings['homepage_slug']  = $page['slug'] ?? '';
        $settings['homepage_title'] = $page['title'] ?? '';
    }

    $GLOBALS['cms_settings_cache']['values'] = $settings;
    $GLOBALS['cms_settings_cache']['loaded'] = true;

    return $settings;
}


/*
|--------------------------------------------------------------------------
| Get a single setting
|--------------------------------------------------------------------------
*/
/**
 * Settings values are stored as text; JSON is decoded back to arrays/scalars.
 */
function decode_setting_value(string $value): mixed
{
    $decoded = json_decode($value, true);

    return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
}

/*
|--------------------------------------------------------------------------
| Save several settings at once
|--------------------------------------------------------------------------
| Writes inside a single transaction and clears the cache once, so a settings
| form does not invalidate the cache per field.
*/
function save_settings(array $settings): void
{
    $pdo = db();
    $started = !$pdo->inTransaction();

    if ($started) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($settings as $key => $value) {
            set_setting($key, $value);
        }

        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    invalidate_cache();
}

function get_setting(string $key, mixed $default = null): mixed
{
    $settings = load_settings();

    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

/**
 * Is a submitted setting value different from the stored one?
 *
 * Stored values keep their type (booleans, integers, arrays) while a form
 * submits strings, so the comparison normalises both sides per type before
 * comparing. Keep this in step with the normalisation in admin/settings.php.
 */
function setting_value_changed(mixed $old, mixed $new): bool
{
    if (is_array($old) || is_array($new)) {
        return (array) $old != (array) $new;
    }

    if (is_bool($old)) {
        return (bool) $old !== (bool) (int) $new;
    }

    if (is_int($old) || is_float($old)) {
        return $old != $new;
    }

    if ($old === null) {
        return $new !== null && $new !== '';
    }

    return (string) $old !== (string) $new;
}

/*
|--------------------------------------------------------------------------
| Set a single setting
|--------------------------------------------------------------------------
*/
function set_setting(string $key, $value): void
{
    $pdo = db();

    // Encode arrays as JSON
    if (is_array($value)) {
        $value = json_encode($value, JSON_THROW_ON_ERROR);
    }

    $stmt = $pdo->prepare("
        INSERT INTO settings (`key`, `value`, updated_at)
        VALUES (:key, :value, :updated_at)
        ON CONFLICT(`key`) DO UPDATE SET
            value = excluded.value,
            updated_at = excluded.updated_at
    ");

    $stmt->execute([
        'key'        => $key,
        'value'      => $value,
        'updated_at' => time(),
    ]);

    // The cached map is now stale — refresh it so later reads in the same
    // request (and tests) see the new value.
    settings_cache_clear();
}

/*
|--------------------------------------------------------------------------
| Maintenance mode
|--------------------------------------------------------------------------
|
| A Settings toggle that closes the public site while the admin stays open.
| The 503 path deliberately depends on nothing but the settings and e(): during
| an upgrade the theme or content tables may be mid-change.
|
*/

/**
 * Is the public site closed for maintenance?
 */
function maintenance_mode_enabled(): bool
{
    return (bool) get_setting('maintenance_mode', false);
}

/**
 * The message visitors see while the site is closed.
 */
function maintenance_message(): string
{
    $message = trim((string) get_setting('maintenance_message', ''));

    return $message !== '' ? $message : 'We are doing a bit of maintenance and will be back shortly.';
}

/**
 * Send the maintenance 503 and stop.
 *
 * Never cached, and Retry-After tells browsers and crawlers when to look again.
 */
function serve_maintenance_response(): void
{
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Type: text/html; charset=utf-8');

    $title = (string) get_setting('site_title', 'Micro CMS');

    echo '<!DOCTYPE html><html lang="' . e(get_setting('site_language', 'en')) . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<meta name="robots" content="noindex, follow">';
    echo '<title>' . e($title) . '</title>';
    echo '<style>body{font:16px/1.6 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1.5rem;color:#1f2937}';
    echo 'h1{font-size:1.3rem;margin:0 0 .5rem}p{margin:0}</style>';
    echo '</head><body><h1>' . e($title) . '</h1><p>' . e(maintenance_message()) . '</p></body></html>';

    exit;
}
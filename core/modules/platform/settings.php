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

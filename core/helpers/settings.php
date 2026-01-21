<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Load All Settings
|--------------------------------------------------------------------------
*/
function load_settings(): array {
    $pdo = db();

    $stmt = $pdo->query("SELECT `key` FROM settings");
    $settings = [];

    while ($row = $stmt->fetch()) {
        $settings[$row['key']] = get_setting($row['key']);
    }

    return $settings;
}

/*
|--------------------------------------------------------------------------
| Save All Settings
|--------------------------------------------------------------------------
*/
function save_settings(array $settings): void
{
    $pdo = db();
    $started = false;

    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $started = true;
    }

    foreach ($settings as $key => $value) {
        set_setting($key, $value);
    }

    if ($started) {
        $pdo->commit();
    }

    invalidate_cache();
}

/*
|--------------------------------------------------------------------------
| Get a single setting
|--------------------------------------------------------------------------
*/
function get_setting(string $key, $default = null) {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT value FROM settings WHERE `key` = :key LIMIT 1");
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();

    if ($row === false) {
        return $default;
    }

    $value = $row['value'];

    // Try JSON decode
    $decoded = json_decode($value, true);

    // If valid JSON, return decoded value
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decoded;
    }

    // Otherwise return raw value
    return $value;
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
}
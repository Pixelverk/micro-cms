<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Load All Settings
|--------------------------------------------------------------------------
*/
function load_settings(): array {
    $settings = [];

    if (file_exists(SETTINGS_FILE)) {
        $json = file_get_contents(SETTINGS_FILE);
        $data = json_decode($json, true);
        if (is_array($data)) {
            $settings = $data;
        }
    }

    return $settings;
}

/*
|--------------------------------------------------------------------------
| Save Settings
|--------------------------------------------------------------------------
*/
function save_settings(array $settings): void {
    file_put_contents(
        SETTINGS_FILE,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    invalidate_cache();
}

/*
|--------------------------------------------------------------------------
| Get a single setting
|--------------------------------------------------------------------------
*/
function get_setting(string $key, $default = null) {
    $settings = load_settings();

    if (array_key_exists($key, $settings)) {
        return $settings[$key];
    }

    return $default;
}

/*
|--------------------------------------------------------------------------
| Set a single setting
|--------------------------------------------------------------------------
*/
function set_setting(string $key, $value): void {
    $settings = load_settings();
    $settings[$key] = $value;
    save_settings($settings);
}
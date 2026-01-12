<?php

define('SETTINGS_FILE', __DIR__ . '/../../_data/settings.json');

function load_settings(): array {
    if (!file_exists(SETTINGS_FILE)) {
        return [];
    }

    $json = file_get_contents(SETTINGS_FILE);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function save_settings(array $settings): void {
    file_put_contents(
        SETTINGS_FILE,
        json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function get_setting(string $key, $default = null) {
    $settings = load_settings();
    return $settings[$key] ?? $default;
}

function set_setting(string $key, $value): void {
    $settings = load_settings();
    $settings[$key] = $value;
    save_settings($settings);
}

<?php
declare(strict_types=1);

function admin_languages(): array
{
    return [
        'en' => 'English',
        'sv' => 'Svenska',
    ];
}

function admin_locale(): string
{
    $user = current_user();
    $locale = $user['ui_language'] ?? get_setting('admin_default_language', 'en');

    return array_key_exists($locale, admin_languages()) ? $locale : 'en';
}

function admin_trans(string $key, array $replace = []): string
{
    static $translations = [];
    $locale = admin_locale();

    if (!isset($translations[$locale])) {
        $file = CMS_PATH . "/admin/lang/{$locale}.php";
        $translations[$locale] = is_file($file) ? require $file : [];
    }

    $text = $translations[$locale][$key] ?? $key;

    foreach ($replace as $name => $value) {
        $text = str_replace(':' . $name, (string)$value, $text);
    }

    return $text;
}

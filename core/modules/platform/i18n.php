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

    // Longest name first: ':pages' has to be replaced before ':page', or the
    // shorter one eats its prefix and leaves "1s" behind.
    $names = array_keys($replace);
    usort($names, static fn($a, $b): int => strlen((string) $b) <=> strlen((string) $a));

    foreach ($names as $name) {
        $text = str_replace(':' . $name, (string) $replace[$name], $text);
    }

    return $text;
}

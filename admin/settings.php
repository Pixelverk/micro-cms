<?php
// admin/settings.php

declare(strict_types=1);

$pageTitle = 'Settings';
$username  = $_SESSION['user_id'] ?? 'User';

$pdo = db();

// ----------------------------
// Load current settings & data
// ----------------------------
$settings = load_settings();
$pages    = list_content('page');
$theme    = theme_config();

$availableLayouts = $theme['layouts'] ?? [];
$availableHeaders = $theme['headers'] ?? [];
$availableFooters = $theme['footers'] ?? [];

// ----------------------------
// Define configurable fields
// ----------------------------
$settingFields = [
    'site_title' => [
        'type'    => 'text',
        'label'   => 'Site title',
        'help'    => 'This will appear in the browser tab and site header.',
        'default' => 'My Site',
    ],
    'homepage_slug' => [
        'type'    => 'select',
        'label'   => 'Homepage',
        'help'    => 'Select which page is the homepage.',
        'options' => array_combine(
            array_column($pages, 'slug'),
            array_column($pages, 'title')
        ),
        'default' => $pages[0]['slug'] ?? '',
    ],
    'site_language' => [
        'type'    => 'text',
        'label'   => 'Site language',
        'help'    => 'Two-letter language code (e.g. en, fr).',
        'default' => 'en',
    ],
    'default_layout' => [
        'type'    => 'select',
        'label'   => 'Default layout',
        'help'    => 'Layout used when content has none set.',
        'options' => $availableLayouts,
        'default' => $settings['default_layout'] ?? $theme['defaults']['layout'],
    ],
    'default_header' => [
        'type'    => 'select',
        'label'   => 'Default header',
        'help'    => 'Header used when content has none set.',
        'options' => $availableHeaders,
        'default' => $settings['default_header'] ?? $theme['defaults']['header'],
    ],
    'default_footer' => [
        'type'    => 'select',
        'label'   => 'Default footer',
        'help'    => 'Footer used when content has none set.',
        'options' => $availableFooters,
        'default' => $settings['default_footer'] ?? $theme['defaults']['footer'],
    ],
    'contact_email' => [
        'type'    => 'text',
        'label'   => 'Contact form email',
        'help'    => 'Messages from contact forms are sent here.',
        'default' => '',
    ],
];

// ----------------------------
// Dynamic prefix fields
// ----------------------------
foreach ($theme['content_types'] ?? [] as $type => $config) {
    $label = $config['label'] ?? ucfirst($type);
    $settingFields["prefix_$type"] = [
        'type'    => 'text',
        'label'   => "$label URL Prefix",
        'help'    => "Optional URL prefix (e.g. /blog/slug). Leave blank for root.",
        'default' => $settings['content_prefixes'][$type]
            ?? $config['url_prefix']
            ?? '',
    ];
}

// ----------------------------
// Handle save
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $newPrefixes = [];

        foreach ($settingFields as $key => $meta) {
            $value = $_POST[$key] ?? null;

            if ($meta['type'] === 'text' && trim($value) === '') {
                if (!str_starts_with($key, 'prefix_')) {
                    throw new RuntimeException("{$meta['label']} cannot be empty.");
                }
            }

            if ($key === 'contact_email' && $value !== '') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Invalid contact email address.');
                }
            }

            if ($meta['type'] === 'select' && !array_key_exists($value, $meta['options'])) {
                throw new RuntimeException("Invalid selection for {$meta['label']}.");
            }

            if (str_starts_with($key, 'prefix_')) {
                $type = substr($key, 7);
                $newPrefixes[$type] = trim($value);
            } else {
                set_setting($key, $value);
            }
        }

        if ($newPrefixes) {
            set_setting('content_prefixes', $newPrefixes);
        }

        $pdo->commit();
        redirect_with_toast('settings', 'success', 'Settings saved successfully.');
    } catch (Throwable $e) {
        $pdo->rollBack();
        redirect_with_toast('settings', 'error', $e->getMessage());
    }
}



// ----------------------------
// Render page
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username) ?> 👋</h2>
        <p>Manage site and CMS settings below.</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="settings">Save Settings</button>
    </div>
</div>

<form id="settings" method="post" class="form-card">
    <?php foreach ($settingFields as $key => $meta): ?>
        <?php
        if (str_starts_with($key, 'prefix_')) {
            $type  = substr($key, strlen('prefix_'));
            $value = $settings['content_prefixes'][$type] ?? $meta['default'] ?? '';
        } else {
            $value = $settings[$key] ?? $meta['default'] ?? '';
        }
        ?>
        <fieldset>
            <legend><?= e($meta['label']) ?></legend>

            <?php if ($meta['type'] === 'text'): ?>
                <label>
                    <input type="text" name="<?= e($key) ?>" value="<?= e($value) ?>">
                    <?php if (!empty($meta['help'])): ?>
                        <small><?= e($meta['help']) ?></small>
                    <?php endif; ?>
                </label>

            <?php elseif ($meta['type'] === 'select'): ?>
                <label>
                    <select name="<?= e($key) ?>">
                        <?php foreach ($meta['options'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $val === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($meta['help'])): ?>
                        <small><?= e($meta['help']) ?></small>
                    <?php endif; ?>
                </label>
            <?php endif; ?>
        </fieldset>
    <?php endforeach; ?>
</form>

<?php
$content = ob_get_clean();
include __DIR__ . '/partials/layout.php';
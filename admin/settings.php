<?php
// admin/settings.php

$pageTitle = 'Settings';
$username = $_SESSION['user_id'] ?? 'User';

// ----------------------------
// Load current settings
// ----------------------------
$settings = load_settings();
$pages    = list_content('page');

// ----------------------------
// Load theme config for layouts, headers, footers
// ----------------------------
$theme = theme_config();

$availableLayouts = $theme['layouts'] ?? [];
$availableHeaders = $theme['headers'] ?? [];
$availableFooters = $theme['footers'] ?? [];

// ----------------------------
// Define all configurable fields
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
        'help'    => 'Two-letter language code, e.g., en, fr, de.',
        'default' => 'en',
    ],
    'default_layout' => [
        'type'    => 'select',
        'label'   => 'Default layout',
        'help'    => 'Layout used if a page has no specific layout.',
        'options' => $availableLayouts,
        'default' => $settings['default_layout'] ?? $theme['defaults']['layout'],
    ],
    'default_header' => [
        'type'    => 'select',
        'label'   => 'Default header component',
        'help'    => 'Component used for site header if not specified on a page.',
        'options' => $availableHeaders,
        'default' => $settings['default_header'] ?? $theme['defaults']['header'],
    ],
    'default_footer' => [
        'type'    => 'select',
        'label'   => 'Default footer component',
        'help'    => 'Component used for site footer if not specified on a page.',
        'options' => $availableFooters,
        'default' => $settings['default_footer'] ?? $theme['defaults']['footer'],
    ],
    'contact_email' => [
    'type'    => 'text',
    'label'   => 'Contact form email',
    'help'    => 'Messages from contact forms will be sent to this address.',
    'default' => '',
    ],
];

// Add content type prefix fields dynamically
foreach ($theme['content_types'] ?? [] as $type => $config) {
    $label = $config['label'] ?? ucfirst($type);
    $settingFields["prefix_$type"] = [
        'type'    => 'text',
        'label'   => "$label URL Prefix",
        'help'    => "Optional URL prefix for $label (e.g., 'blog' → /blog/slug). Leave blank for root-level.",
        'default' => $settings['content_prefixes'][$type] ?? $config['url_prefix'] ?? '',
    ];
}

// ----------------------------
// Handle save
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPrefixes = [];

    foreach ($settingFields as $key => $meta) {
        $value = $_POST[$key] ?? null;

        if ($meta['type'] === 'text' && trim($value) === '') {
            // Allow empty for prefixes
            if (!str_starts_with($key, 'prefix_')) {
                redirect_with_toast('settings', 'error', "{$meta['label']} cannot be empty.");
            }
        }

        if ($key === 'contact_email' && $value !== '') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                redirect_with_toast('settings', 'error', 'Invalid contact email address.');
            }
        }

        if ($meta['type'] === 'select' && !array_key_exists($value, $meta['options'])) {
            redirect_with_toast('settings', 'error', "Invalid selection for {$meta['label']}.");
        }

        // Save prefix values separately
        if (str_starts_with($key, 'prefix_')) {
            $type = substr($key, strlen('prefix_'));
            $newPrefixes[$type] = trim($value);
        } else {
            set_setting($key, $value);
        }
    }

    if ($newPrefixes) {
        set_setting('content_prefixes', $newPrefixes);
    }

    redirect_with_toast('settings', 'success', 'Settings saved successfully.');
}

// ----------------------------
// Render page
// ----------------------------
ob_start();
?>

<div class="page-header">
    <div class="page-title">
        <h2>Hello, <?= e($username); ?> 👋</h2>
        <p>Manage site and CMS settings below.</p>
    </div>
    <div class="page-actions">
        <button type="submit" form="settings">Save Settings</button>
    </div>
</div>

<form id="settings" method="post" class="form-card">
    <?php foreach ($settingFields as $key => $meta): ?>
        <fieldset>
            <legend><?= e($meta['label']) ?></legend>

            <?php if ($meta['type'] === 'text'): ?>
                <label>
                    <input
                        type="text"
                        name="<?= e($key) ?>"
                        value="<?= e($settings[$key] ?? $meta['default'] ?? '') ?>"
                    >
                    <?php if (!empty($meta['help'])): ?>
                        <small><?= e($meta['help']) ?></small>
                    <?php endif; ?>
                </label>

            <?php elseif ($meta['type'] === 'select'): ?>
                <label>
                    <select name="<?= e($key) ?>">
                        <?php foreach ($meta['options'] as $val => $label): ?>
                            <option
                                value="<?= e($val) ?>"
                                <?= ($settings[$key] ?? $meta['default'] ?? '') === $val ? 'selected' : '' ?>
                            >
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
?>
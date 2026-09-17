<?php
// admin/settings.php

declare(strict_types=1);

$pageTitle = admin_trans('nav_settings');
$username  = current_username();

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
        'label'   => 'settings_site_title',
        'help'    => 'settings_site_title_help',
        'default' => 'My Site',
    ],
    'homepage_id' => [
        'type'    => 'select',
        'label'   => 'settings_homepage',
        'help'    => 'settings_homepage_help',
        'options' => array_combine(
            array_column($pages, 'id'),
            array_column($pages, 'title')
        ),
        'default' => $pages[0]['id'] ?? null,
    ],
    'site_language' => [
        'type'    => 'text',
        'label'   => 'settings_site_language',
        'help'    => 'settings_site_language_help',
        'default' => 'en',
    ],
    'site_url' => [
        'type'    => 'text',
        'label'   => 'settings_site_url',
        'help'    => 'settings_site_url_help',
        'default' => '',
    ],
    'seo_title_suffix' => [
        'type'    => 'text',
        'label'   => 'settings_title_suffix',
        'help'    => 'settings_title_suffix_help',
        'default' => '',
    ],
    'default_og_image' => [
        'type'    => 'text',
        'label'   => 'settings_og_image',
        'help'    => 'settings_og_image_help',
        'default' => '',
    ],
    'twitter_site' => [
        'type'    => 'text',
        'label'   => 'settings_twitter',
        'help'    => 'settings_twitter_help',
        'default' => '',
    ],
    'robots_extra' => [
        'type'    => 'textarea',
        'label'   => 'settings_robots_extra',
        'help'    => 'settings_robots_extra_help',
        'default' => '',
    ],
    'admin_default_language' => [
        'type'    => 'select',
        'label'   => 'settings_admin_language',
        'help'    => 'settings_admin_language_help',
        'options' => admin_languages(),
        'default' => 'en',
    ],
    'default_layout' => [
        'type'    => 'select',
        'label'   => 'settings_default_layout',
        'help'    => 'settings_default_layout_help',
        'options' => $availableLayouts,
        'default' => $settings['default_layout'] ?? $theme['defaults']['layout'],
    ],
    'default_header' => [
        'type'    => 'select',
        'label'   => 'settings_default_header',
        'help'    => 'settings_default_header_help',
        'options' => $availableHeaders,
        'default' => $settings['default_header'] ?? $theme['defaults']['header'],
    ],
    'default_footer' => [
        'type'    => 'select',
        'label'   => 'settings_default_footer',
        'help'    => 'settings_default_footer_help',
        'options' => $availableFooters,
        'default' => $settings['default_footer'] ?? $theme['defaults']['footer'],
    ],
    'contact_email' => [
        'type'    => 'text',
        'label'   => 'settings_contact_email',
        'help'    => 'settings_contact_email_help',
        'default' => '',
    ],

    // ----------------------------
    // Media upload settings
    'generate_webp' => [
        'type'    => 'checkbox',
        'label'   => 'settings_webp',
        'help'    => 'settings_webp_help',
        'default' => true,
    ],
    'quality_webp' => [
        'type'    => 'number',
        'label'   => 'settings_webp_quality',
        'help'    => 'settings_webp_quality_help',
        'default' => 80,
        'min'     => 1,
        'max'     => 100,
    ],
    'strip_metadata' => [
        'type'    => 'checkbox',
        'label'   => 'settings_strip_metadata',
        'help'    => 'settings_strip_metadata_help',
        'default' => true,
    ],
    'media_sizes' => [
        'type'    => 'text',
        'label'   => 'settings_media_sizes',
        'help'    => 'settings_media_sizes_help',
        'default' => '320,640,1280',
    ],
    'allow_svg' => [
        'type'    => 'checkbox',
        'label'   => 'settings_allow_svg',
        'help'    => 'settings_allow_svg_help',
        'default' => false,
    ],

    // ----------------------------
    // Header/footer code
    // Written raw into every public page, so this is trusted-admin-only input
    // (the page already requires settings.manage).
    'header_scripts' => [
        'type'    => 'textarea',
        'label'   => 'settings_header_scripts',
        'help'    => 'settings_header_scripts_help',
        'default' => '',
    ],
    'footer_scripts' => [
        'type'    => 'textarea',
        'label'   => 'settings_footer_scripts',
        'help'    => 'settings_footer_scripts_help',
        'default' => '',
    ],
];

// ----------------------------
// Dynamic prefix fields
// ----------------------------
foreach ($theme['content_types'] ?? [] as $type => $config) {
    $label = $config['label'] ?? ucfirst($type);
    $settingFields["prefix_$type"] = [
        'type'          => 'text',
        'label'         => 'settings_prefix_label',
        'label_replace' => ['type' => $label],
        'help'          => 'settings_prefix_help',
        'default'       => $settings['content_prefixes'][$type]
            ?? $config['url_prefix']
            ?? '',
    ];
}

// ----------------------------
// Handle save
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $errors      = [];
    $newValues   = [];
    $newPrefixes = [];

    foreach ($settingFields as $key => $meta) {
        $raw = $_POST[$key] ?? null;

        // ----------------------------
        // Normalise
        // ----------------------------
        if ($meta['type'] === 'checkbox') {
            $value = !empty($raw);
        } elseif ($meta['type'] === 'number') {
            $value = $raw === '' || $raw === null ? null : (int) $raw;
        } elseif ($key === 'media_sizes') {
            $parsed = validate_sizes_csv($raw);

            if ($parsed === null) {
                $errors[$key] = admin_trans('settings_error_media_sizes', ['label' => admin_trans((string) $meta['label'])]);
                continue;
            }

            $value = $parsed;
        } else {
            $value = is_string($raw) ? trim($raw) : $raw;
        }

        // ----------------------------
        // Validate
        // ----------------------------
        $label = admin_trans((string) ($meta['label'] ?? $key), $meta['label_replace'] ?? []);

        if (str_starts_with($key, 'prefix_')) {
            if (!is_string($value) || !validate_url_prefix($value)) {
                $errors[$key] = admin_trans('settings_error_prefix', ['label' => $label]);
                continue;
            }
        } elseif ($meta['type'] === 'select') {
            if (!validate_enum((string) $value, array_map('strval', array_keys($meta['options'] ?? [])))) {
                $errors[$key] = admin_trans('settings_error_selection', ['label' => $label]);
                continue;
            }
        } elseif ($key === 'contact_email') {
            if (!validate_email((string) $value, true)) {
                $errors[$key] = admin_trans('settings_error_email');
                continue;
            }
        } elseif ($key === 'homepage_id') {
            if ($value === null || $value === '' || $value === 0) {
                $errors[$key] = admin_trans('settings_error_homepage');
                continue;
            }

            if (!load_content_by_id((int) $value)) {
                $errors[$key] = admin_trans('settings_error_homepage_missing');
                continue;
            }
        } elseif ($key === 'site_language') {
            if (!validate_language_code((string) $value)) {
                $errors[$key] = admin_trans('settings_error_language');
                continue;
            }
        } elseif ($key === 'site_url') {
            // Blank means "work it out from the request"; otherwise it must be
            // an absolute origin so canonical URLs are trustworthy.
            if ($value !== '' && !preg_match('#^https?://[a-z0-9.\-]+(:\d+)?$#i', (string) $value)) {
                $errors[$key] = admin_trans('settings_error_site_url');
                continue;
            }

            $value = rtrim((string) $value, '/');
        } elseif ($key === 'default_og_image') {
            $value = trim((string) $value);

            if ($value !== '' && !ctype_digit($value) && !validate_url($value) && !preg_match('#^[a-z0-9._\-/]+\.(jpe?g|png|gif|webp|avif)$#i', $value)) {
                $errors[$key] = admin_trans('settings_error_og_image');
                continue;
            }
        } elseif ($key === 'twitter_site') {
            $value = trim((string) $value);

            if ($value !== '' && !preg_match('/^@?[A-Za-z0-9_]{1,30}$/', $value)) {
                $errors[$key] = admin_trans('settings_error_twitter');
                continue;
            }
        } elseif ($meta['type'] === 'number') {
            $min = (int) ($meta['min'] ?? 0);
            $max = (int) ($meta['max'] ?? PHP_INT_MAX);

            if ($value === null || !validate_int_range($value, $min, $max)) {
                $errors[$key] = "$label must be between $min and $max.";
                continue;
            }
        } elseif ($meta['type'] === 'text' && ($meta['required'] ?? false)) {
            if (!validate_required($value)) {
                $errors[$key] = "$label cannot be empty.";
                continue;
            }
        }

        // ----------------------------
        // Stage
        // ----------------------------
        if (str_starts_with($key, 'prefix_')) {
            $newPrefixes[substr($key, strlen('prefix_'))] = (string) $value;
        } else {
            $newValues[$key] = $value;
        }
    }

    if ($errors) {
        validate_throw($errors, 'settings');
    }

    $changedKeys = implode(', ', array_keys($newValues));

    if ($newPrefixes) {
        $newValues['content_prefixes'] = $newPrefixes;
    }

    try {
        save_settings($newValues);

        log_activity('settings.updated', 'settings', null, $changedKeys, []);

        redirect_with_toast('settings', 'success', admin_trans('settings_saved'));
    } catch (Throwable $e) {
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
        <h2><?= e(admin_trans('common_hello', ['name' => $username])) ?></h2>
        <p><?= e(admin_trans('settings_intro')) ?></p>
    </div>
    <div class="page-actions">
        <button type="submit" form="settings"><?= e(admin_trans('common_save')) ?></button>
    </div>
</div>

<form id="settings" method="post" class="form-card">
    <?= csrf_field() ?>
    <?php foreach ($settingFields as $key => $meta): ?>
        <?php
        if ($key === 'homepage_id') {
            $value = $settings['homepage_id'] ?? $meta['default'] ?? '';
        } elseif (str_starts_with($key, 'prefix_')) {
            $type  = substr($key, strlen('prefix_'));
            $value = $settings['content_prefixes'][$type] ?? $meta['default'] ?? '';
        } else {
            $value = $settings[$key] ?? $meta['default'] ?? '';
        }
        ?>
        <fieldset>
            <legend><?= e(admin_trans($meta['label'], $meta['label_replace'] ?? [])) ?></legend>

            <?php if ($meta['type'] === 'text' || $meta['type'] === 'number'): ?>
                <label>
                    <input
                        type="<?= $meta['type'] === 'number' ? 'number' : 'text' ?>"
                        name="<?= e($key) ?>"
                        value="<?= is_array($value) ? e(implode(',', $value)) : e($value) ?>"
                        <?= $meta['min'] ?? '' ? "min=\"{$meta['min']}\"" : '' ?>
                        <?= $meta['max'] ?? '' ? "max=\"{$meta['max']}\"" : '' ?>
                    >
                    <?php if (!empty($meta['help'])): ?>
                        <small><?= e(admin_trans($meta['help'], $meta['help_replace'] ?? [])) ?></small>
                    <?php endif; ?>
                </label>

            <?php elseif ($meta['type'] === 'select'): ?>
                <label>
                    <select name="<?= e($key) ?>">
                        <?php foreach ($meta['options'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= ((string)$val === (string)$value) ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($meta['help'])): ?>
                        <small><?= e(admin_trans($meta['help'], $meta['help_replace'] ?? [])) ?></small>
                    <?php endif; ?>
                </label>

            <?php elseif ($meta['type'] === 'checkbox'): ?>
                <label>
                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= $value ? 'checked' : '' ?>>
                    <?php if (!empty($meta['help'])): ?>
                        <small><?= e(admin_trans($meta['help'], $meta['help_replace'] ?? [])) ?></small>
                    <?php endif; ?>
                </label>

            <?php elseif ($meta['type'] === 'textarea'): ?>
                <label>
                    <textarea name="<?= e($key) ?>" rows="6"><?= e((string) $value) ?></textarea>
                    <?php if (!empty($meta['help'])): ?>
                        <small><?= e(admin_trans($meta['help'], $meta['help_replace'] ?? [])) ?></small>
                    <?php endif; ?>
                </label>
            <?php endif; ?>

        </fieldset>
    <?php endforeach; ?>
</form>

<?php
$content = ob_get_clean();

ob_start();
?>
<h3><?= e(admin_trans('nav_settings')) ?></h3>
<p><?= e(admin_trans('settings_help')) ?></p>
<ul>
    <li><?= e(admin_trans('settings_help_site')) ?></li>
    <li><?= e(admin_trans('settings_help_media')) ?></li>
    <li><?= e(admin_trans('settings_help_seo')) ?></li>
    <li><?= e(admin_trans('settings_help_code')) ?></li>
</ul>
<?php
$pageHelp = ob_get_clean();
$docsLink = ['tab' => 'reference', 'section' => 'configuration'];

include CMS_PATH . '/admin/partials/layout.php';
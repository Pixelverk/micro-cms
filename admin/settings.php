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
// Field groups
// ----------------------------
// `columns` is the number of tracks the group's grid has; a field either takes
// one track or spans the whole row. Tracks are the only thing that decides a
// field's width, so no input needs a max-width of its own.
$settingGroups = [
    'general' => [
        'label'   => 'settings_group_general',
        'icon'    => 'settings',
        'columns' => 2,
        'fields'  => ['site_title', 'site_language', 'homepage_id', 'site_url', 'contact_email'],
    ],
    'account' => [
        'label'   => 'settings_group_account',
        'icon'    => 'profile-circle',
        'columns' => 2,
        'fields'  => ['admin_default_language'],
    ],
    'design' => [
        'label'   => 'settings_group_design',
        'icon'    => 'book',
        'columns' => 3,
        'fields'  => ['default_layout', 'default_header', 'default_footer'],
    ],
    'seo' => [
        'label'   => 'settings_group_seo',
        'icon'    => 'open-in-browser',
        'columns' => 2,
        'fields'  => ['seo_title_suffix', 'default_og_image', 'twitter_site', 'robots_extra'],
    ],
    'media' => [
        'label'   => 'settings_group_media',
        'icon'    => 'media-image',
        'columns' => 2,
        'fields'  => ['generate_webp', 'quality_webp', 'strip_metadata', 'allow_svg', 'media_sizes'],
    ],
    'code' => [
        'label'   => 'settings_group_code',
        'icon'    => 'wrench',
        'columns' => 2,
        'fields'  => ['header_scripts', 'footer_scripts'],
    ],
    'urls' => [
        'label'   => 'settings_group_urls',
        'icon'    => 'label',
        'columns' => 3,
        'fields'  => [], // filled with the prefix_* fields below
    ],
];

// Fields that take the whole row rather than one track: the long ones, and the
// comma list that would otherwise sit under a checkbox column.
$settingSpanFields = ['media_sizes', 'robots_extra'];

// ----------------------------
// Dynamic prefix fields
// ----------------------------
foreach ($theme['content_types'] ?? [] as $type => $config) {
    $label = $config['label'] ?? ucfirst($type);
    $key = "prefix_$type";
    $settingFields[$key] = [
        'type'          => 'text',
        'label'         => 'settings_prefix_label',
        'label_replace' => ['type' => $label],
        'help'          => 'settings_prefix_help',
        'default'       => $settings['content_prefixes'][$type]
            ?? $config['url_prefix']
            ?? '',
    ];
    $settingGroups['urls']['fields'][] = $key;
}

// ----------------------------
// Handle save
// ----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $errors      = [];
    $newValues   = [];
    $newPrefixes = [];
    $oldValues   = $settings;

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

    // The form posts every field, so compare against the stored values and
    // record only what actually changed. A setting with no row yet is compared
    // against the field's default, or an untouched checkbox would look edited.
    $changedLabels = [];

    foreach ($newValues as $key => $value) {
        $before = array_key_exists($key, $oldValues)
            ? $oldValues[$key]
            : ($settingFields[$key]['default'] ?? null);

        if (setting_value_changed($before, $value)) {
            $changedLabels[] = admin_trans((string) ($settingFields[$key]['label'] ?? $key), $settingFields[$key]['label_replace'] ?? []);
        }
    }

    // Prefix fields are collected separately, so compare each against the
    // stored content_prefixes entry and log it under its own label.
    foreach ($newPrefixes as $type => $prefix) {
        $key = "prefix_$type";

        if (setting_value_changed($oldValues['content_prefixes'][$type] ?? null, $prefix)) {
            $changedLabels[] = admin_trans((string) ($settingFields[$key]['label'] ?? $key), $settingFields[$key]['label_replace'] ?? []);
        }
    }

    $changedKeys = implode(', ', $changedLabels);

    if ($newPrefixes) {
        $newValues['content_prefixes'] = $newPrefixes;
    }

    try {
        save_settings($newValues);

        if ($changedKeys !== '') {
            log_activity('settings.updated', 'settings', null, $changedKeys, []);
        }

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

<form id="settings" method="post">
    <?= csrf_field() ?>
    <?php foreach ($settingGroups as $groupKey => $group): ?>
        <?php
        $groupFields = array_filter(
            array_map(fn($name) => isset($settingFields[$name]) ? [$name, $settingFields[$name]] : null, $group['fields'])
        );

        if (!$groupFields) {
            continue;
        }
        ?>
        <fieldset class="settings-group">
            <legend>
                <?= icon($group['icon'], 18) ?>
                <?= e(admin_trans($group['label'])) ?>
            </legend>

            <?php
            $groupColumns = max(1, (int) ($group['columns'] ?? 2));
            // The column count is expressed as a class so no inline style is
            // needed; the stylesheet owns the widths.
            $gridClass = 'field-grid' . ($groupColumns === 2 ? '' : ' field-grid-' . $groupColumns);
            ?>
            <div class="<?= e($gridClass) ?> card">
                <?php foreach ($groupFields as [$key, $meta]): ?>
                    <?php
                    if ($key === 'homepage_id') {
                        $value = $settings['homepage_id'] ?? $meta['default'] ?? '';
                    } elseif (str_starts_with($key, 'prefix_')) {
                        $type  = substr($key, strlen('prefix_'));
                        $value = $settings['content_prefixes'][$type] ?? $meta['default'] ?? '';
                    } else {
                        $value = $settings[$key] ?? $meta['default'] ?? '';
                    }

                    $fieldId = 'setting-' . str_replace('_', '-', $key);
                    $label   = admin_trans($meta['label'], $meta['label_replace'] ?? []);
                    $help    = !empty($meta['help'])
                        ? admin_trans($meta['help'], $meta['help_replace'] ?? [])
                        : '';

                    $classes = ['field'];
                    if (in_array($key, $settingSpanFields, true)) {
                        $classes[] = 'field-span';
                    }
                    ?>

                    <?php if ($meta['type'] === 'checkbox'): ?>
                        <div class="<?= e(implode(' ', $classes)) ?> field-check">
                            <input type="checkbox" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" value="1" <?= $value ? 'checked' : '' ?>>
                            <label class="field-label" for="<?= e($fieldId) ?>"><?= e($label) ?></label>
                            <?php if ($help !== ''): ?>
                                <small><?= e($help) ?></small>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($meta['type'] === 'textarea'): ?>
                        <div class="<?= e(implode(' ', $classes)) ?>">
                            <label class="field-label" for="<?= e($fieldId) ?>"><?= e($label) ?></label>
                            <textarea class="field-input" id="<?= e($fieldId) ?>" name="<?= e($key) ?>" rows="6"><?= e((string) $value) ?></textarea>
                            <?php if ($help !== ''): ?>
                                <small><?= e($help) ?></small>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($meta['type'] === 'select'): ?>
                        <div class="<?= e(implode(' ', $classes)) ?>">
                            <label class="field-label" for="<?= e($fieldId) ?>"><?= e($label) ?></label>
                            <select class="field-input" id="<?= e($fieldId) ?>" name="<?= e($key) ?>">
                                <?php foreach ($meta['options'] as $optionValue => $optionLabel): ?>
                                    <option value="<?= e($optionValue) ?>" <?= ((string) $optionValue === (string) $value) ? 'selected' : '' ?>>
                                        <?= e($optionLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($help !== ''): ?>
                                <small><?= e($help) ?></small>
                            <?php endif; ?>
                        </div>

                    <?php else: ?>
                        <div class="<?= e(implode(' ', $classes)) ?>">
                            <label class="field-label" for="<?= e($fieldId) ?>"><?= e($label) ?></label>
                            <input
                                class="field-input<?= str_starts_with($key, 'prefix_') ? ' content-prefix' : '' ?>"
                                type="<?= $meta['type'] === 'number' ? 'number' : 'text' ?>"
                                id="<?= e($fieldId) ?>"
                                name="<?= e($key) ?>"
                                value="<?= is_array($value) ? e(implode(',', $value)) : e($value) ?>"
                                <?= $meta['min'] ?? '' ? "min=\"{$meta['min']}\"" : '' ?>
                                <?= $meta['max'] ?? '' ? "max=\"{$meta['max']}\"" : '' ?>
                            >
                            <?php if ($help !== ''): ?>
                                <small><?= e($help) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
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
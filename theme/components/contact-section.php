<?php
// theme/components/contact-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Contact Section',
'description' => "A contact panel: icon, message and a form.",

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'form_type' => [
        'type' => 'select',
        'label' => 'Form Type',
        'required' => true,
        'options' => [
            'contact'    => 'Contact',
            'newsletter' => 'Newsletter',
        ],
    ],
    'title' => [
        'type' => 'text',
        'label' => 'Form Title',
        'required' => false,
        'default' => 'Contact Us'
    ],
    'description' => [
        'type' => 'text',
        'label' => 'Form Description',
        'required' => false,
        'default' => 'Send us a message and we will get back to you.'
    ],
    'icon' => [
        'type' => 'icon',
        'label' => 'Header Icon',
        'required' => false,
        'default' => 'envelope'
    ],
    'success_message' => [
        'type' => 'text',
        'label' => 'Success Message',
        'required' => false,
        'default' => 'Thanks! Your message has been sent.'
    ],
    'error_message' => [
        'type' => 'text',
        'label' => 'Error Message',
        'required' => false,
        'default' => 'Something went wrong. Please try again later.'
    ],
    'redirect_url' => [
        'type' => 'text',
        'label' => 'Redirect URL (optional)',
        'required' => false,
        'default' => '',
        'help' => 'Optional: redirect to a thank-you page after successful submission.'
    ],
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'none',
'allowed_children' => [],

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function(array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {

    extract($props, EXTR_SKIP);

    $id = 'contact-section-' . uniqid();

    if (empty($form_type)) {
        return;
    }

    ?>
    <section id="<?= e($id) ?>" class="contact-form py-5">
        <div class="container px-5">
            <div class="bg-light rounded-3 py-5 px-4 px-md-5 mb-5">
                <div class="text-center mb-5">
                    <?php $iconMarkup = theme_icon((string) ($icon ?? 'envelope')); ?>
                    <?php if ($iconMarkup !== ''): ?><div class="feature bg-primary bg-gradient text-white rounded-3 mb-3"><?= $iconMarkup ?></div><?php endif; ?>
                    <?php if (!empty($title)): ?>
                        <h2 class="h1 fw-bolder"><?= e($title) ?></h2>
                    <?php endif; ?>
                    <?php if (!empty($description)): ?>
                        <p class="lead fw-normal text-muted mb-0"><?= e($description) ?></p>
                    <?php endif; ?>
                </div>
                <div class="row gx-5 justify-content-center">
                    <div class="col-lg-8 col-xl-6">
                        <?php
                        // The fields, token, honeypot and submit handling all come
                        // from the shared partial, so a second form on a page
                        // behaves identically.
                        $form = [
                            'type'     => (string) $form_type,
                            'id'       => $id,
                            'submit'   => 'Send',
                            'success'  => (string) ($success_message ?? ''),
                            'error'    => (string) ($error_message ?? ''),
                            'redirect' => (string) ($redirect_url ?? ''),
                            'page_id'  => (int) ($props['_page_id'] ?? 0),
                        ];

                        require theme('partials/form.php');
                        ?>
                    </div>
                </div>
            </div>
        </div>

    </section>

    <?php
},

];

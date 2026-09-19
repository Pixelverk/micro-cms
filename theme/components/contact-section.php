<?php
// theme/components/contact-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Contact Section',

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
        'type' => 'text',
        'label' => 'Header Icon',
        'required' => false,
        'default' => 'bi-envelope'
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
 * Component CSS
 * -------------------------------------------- */
'css' => <<<CSS
.contact-form .special-field { position: absolute; left: -9999px; top: -9999px; height: 0; overflow: hidden; }
.contact-form .message { margin-top: 1rem; display: none; }
.contact-form .message.success { color: #2e7d32; }
.contact-form .message.error { color: #c62828; }
CSS,

/** --------------------------------------------
 * Component JS
 * -------------------------------------------- */
'js' => <<<JS
document.addEventListener('submit', function(e){
    const form = e.target.closest('.contact-form form');
    if (!form) return;

    e.preventDefault();

    const messageBox = form.querySelector('.message');
    messageBox.style.display = 'none';

    submitForm();

    // Posts the form. If the signed token has expired (page served from cache),
    // fetch a fresh one and retry exactly once.
    function submitForm(retried = false) {
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: {'Accept':'application/json'}
        })
        .then(res => res.json().then(data => ({status: res.status, data})))
        .then(({status, data}) => {
            if (status === 419 && data.stale && !retried) {
                return refreshToken().then(ok => {
                    if (ok) submitForm(true);
                    else showError(messageBox, form.dataset.error);
                });
            }

            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            if (!data.success && data.error && status >= 400) {
                showError(messageBox, data.error);
                return;
            }

            messageBox.textContent = data.success
                ? form.dataset.success
                : form.dataset.error;
            messageBox.className = 'message ' + (data.success ? 'success':'error');
            messageBox.style.display = 'block';
            if (data.success) form.reset();
        })
        .catch(() => showError(messageBox, form.dataset.error));
    }

    function refreshToken() {
        const formType = form.querySelector('[name="form_type"]')?.value;
        if (!formType) return Promise.resolve(false);

        const url = new URL(form.action, window.location.origin);
        url.search = '';
        url.pathname = url.pathname.replace(/\/form-submit\/?$/, '/form-token');
        url.searchParams.set('form_type', formType);

        return fetch(url, {headers: {'Accept':'application/json'}})
            .then(res => res.ok ? res.json() : null)
            .then(data => {
                const input = form.querySelector('[name="_form_token"]');
                if (!data || !data.token || !input) return false;
                input.value = data.token;
                return true;
            })
            .catch(() => false);
    }

    function showError(messageBox, text) {
        messageBox.textContent = text;
        messageBox.className = 'message error';
        messageBox.style.display = 'block';
    }
});
JS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function(array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {

    extract($props, EXTR_SKIP);
    $id = 'contact-section-' . uniqid();

    $formType = $props['form_type'] ?? null;
    if (!$formType) return; // admin: could show warning

    $theme = theme_config();
    $formConfig = $theme['form_types'][$formType] ?? [];
    $fields = $formConfig['fields'] ?? [];

    ?>

    <section id="<?= e($id) ?>" class="contact-form py-5">
        <div class="container px-5">
            <div class="bg-light rounded-3 py-5 px-4 px-md-5 mb-5">
                <div class="text-center mb-5">
                    <div class="feature bg-primary bg-gradient text-white rounded-3 mb-3"><i class="bi <?= e($icon ?? 'bi-envelope') ?>"></i></div>
                    <?php if (!empty($title)): ?>
                        <h1 class="fw-bolder"><?= e($title) ?></h1>
                    <?php endif; ?>
                    <?php if (!empty($description)): ?>
                        <p class="lead fw-normal text-muted mb-0"><?= e($description) ?></p>
                    <?php endif; ?>
                </div>
                <div class="row gx-5 justify-content-center">
                    <div class="col-lg-8 col-xl-6">
                        <form action="<?= url('form-submit') ?>" method="post"
                              data-success="<?= e($success_message) ?>"
                              data-error="<?= e($error_message) ?>">

            <input type="hidden" name="form_type" value="<?= e($formType) ?>">
            <?= form_token_field($formType) ?>

            <?php if (!empty($props['_page_id'])): ?>
                <input type="hidden" name="page_id" value="<?= (int)$props['_page_id'] ?>">
            <?php endif; ?>

            <?php if (!empty($props['redirect_url'])): ?>
                <input type="hidden" name="redirect_url" value="<?= e($props['redirect_url']) ?>">
            <?php endif; ?>

                            <!-- Honeypot -->
                            <label class="special-field">
                                Company
                                <input type="text" name="company" tabindex="-1" autocomplete="off">
                            </label>

                            <!-- Dynamic fields -->
                            <?php foreach ($fields as $name => $cfg): ?>
                                <?php
                                    $fieldType  = $cfg['type'] ?? 'text';
                                    $isRequired = !empty($cfg['required']) ? 'required' : '';
                                    $fieldId    = $id . '-' . $name;
                                    $fieldLabel = (string) ($cfg['label'] ?? ucfirst(str_replace('_', ' ', $name)));
                                    $options    = is_array($cfg['options'] ?? null) ? $cfg['options'] : [];
                                ?>
                                <?php if ($fieldType === 'checkbox'): ?>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" id="<?= e($fieldId) ?>" type="checkbox" name="<?= e($name) ?>" <?= $isRequired ?> />
                                        <label class="form-check-label" for="<?= e($fieldId) ?>"><?= e($fieldLabel) ?></label>
                                    </div>
                                <?php elseif ($fieldType === 'select'): ?>
                                    <div class="form-floating mb-3">
                                        <select class="form-control" id="<?= e($fieldId) ?>" name="<?= e($name) ?>" <?= $isRequired ?>>
                                            <option value=""></option>
                                            <?php foreach ($options as $optionValue => $optionLabel): ?>
                                                <option value="<?= e((string) $optionValue) ?>"><?= e((string) $optionLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label for="<?= e($fieldId) ?>"><?= e($fieldLabel) ?></label>
                                    </div>
                                <?php elseif ($fieldType === 'radio'): ?>
                                    <fieldset class="mb-3">
                                        <legend class="form-check-label"><?= e($fieldLabel) ?></legend>
                                        <?php foreach ($options as $optionValue => $optionLabel): ?>
                                            <?php $optionId = $fieldId . '-' . $optionValue; ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="radio" id="<?= e($optionId) ?>" name="<?= e($name) ?>" value="<?= e((string) $optionValue) ?>" <?= $isRequired ?> />
                                                <label class="form-check-label" for="<?= e($optionId) ?>"><?= e((string) $optionLabel) ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </fieldset>
                                <?php else: ?>
                                    <div class="form-floating mb-3">
                                        <?php if ($fieldType === 'textarea'): ?>
                                            <textarea class="form-control" id="<?= e($fieldId) ?>" name="<?= e($name) ?>" placeholder="<?= e($fieldLabel) ?>" style="height: 10rem" <?= $isRequired ?>></textarea>
                                        <?php else: ?>
                                            <input class="form-control" id="<?= e($fieldId) ?>" type="<?= e($fieldType) ?>" name="<?= e($name) ?>" placeholder="<?= e($fieldLabel) ?>" <?= $isRequired ?> />
                                        <?php endif; ?>
                                        <label for="<?= e($fieldId) ?>"><?= e($fieldLabel) ?></label>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <div class="d-grid"><button class="btn btn-primary btn-lg" type="submit">Send</button></div>
                            <div class="message"></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </section>

    <?php
},

];
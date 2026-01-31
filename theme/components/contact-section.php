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
.contact-form {
    max-width: 600px;
    margin: 3rem auto;
    padding: 2rem;
    background: #f7f7f7;
    border-radius: 6px;
}
.contact-form h2 { margin-bottom: 0.5rem; }
.contact-form p { margin-bottom: 1.5rem; color: #555; }
.contact-form label { display: block; margin-bottom: 0.25rem; font-weight: 600; }
.contact-form input,
.contact-form textarea { width: 100%; padding: 0.6rem; margin-bottom: 1rem; border: 1px solid #ccc; border-radius: 4px; }
.contact-form button { padding: 0.6rem 1.5rem; background: #00796b; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
.contact-form .message { margin-top: 1rem; display: none; }
.contact-form .message.success { color: #2e7d32; }
.contact-form .message.error { color: #c62828; }
.contact-form .special-field { position: absolute; left: -9999px; top: -9999px; height: 0; overflow: hidden; }
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

    fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        headers: {'Accept':'application/json'}
    })
    .then(res => res.json())
    .then(data => {
        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }
        messageBox.textContent = data.success
            ? form.dataset.success
            : form.dataset.error;
        messageBox.className = 'message ' + (data.success ? 'success':'error');
        messageBox.style.display = 'block';
        if (data.success) form.reset();
    })
    .catch(() => {
        messageBox.textContent = form.dataset.error;
        messageBox.className = 'message error';
        messageBox.style.display = 'block';
    });
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

    <section id="<?= $id ?>" class="contact-form">

        <?php if (!empty($title)): ?>
            <h2><?= e($title) ?></h2>
        <?php endif; ?>

        <?php if (!empty($description)): ?>
            <p><?= e($description) ?></p>
        <?php endif; ?>

        <form action="<?= url('form-submit') ?>" method="post"
              data-success="<?= e($success_message) ?>"
              data-error="<?= e($error_message) ?>">

            <input type="hidden" name="form_type" value="<?= e($formType) ?>">

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
                    $fieldType = $cfg['type'] ?? 'text';
                    $isRequired = !empty($cfg['required']) ? 'required' : '';
                ?>
                <?php if ($fieldType === 'checkbox'): ?>
                    <label>
                        <input type="checkbox" name="<?= e($name) ?>" <?= $isRequired ?> />
                        <?= ucfirst($name) ?>
                    </label>
                <?php elseif ($fieldType === 'textarea'): ?>
                    <label>
                        <?= ucfirst($name) ?>
                        <textarea name="<?= e($name) ?>" <?= $isRequired ?>></textarea>
                    </label>
                <?php else: ?>
                    <label>
                        <?= ucfirst($name) ?>
                        <input type="<?= e($fieldType) ?>" name="<?= e($name) ?>" <?= $isRequired ?> />
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>

            <button type="submit">Send</button>
            <div class="message"></div>
        </form>

    </section>

    <?php
},

];
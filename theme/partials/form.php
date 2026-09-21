<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public form partial
|--------------------------------------------------------------------------
|
| Renders a form declared under form_types in the manifest, so every component
| that shows one gets the same fields, honeypot, token and submit handling.
| Included with a $form array:
|
|   $form = [
|       'type'     => 'contact',   // form_types key
|       'variant'  => 'stacked',   // 'stacked' for a card, 'inline' for a signup bar
|       'submit'   => 'Send',
|       'success'  => 'Thanks!',
|       'error'    => 'Something went wrong.',
|       'redirect' => '',          // optional thank-you URL
|       'page_id'  => 12,          // optional, stored with the submission
|   ];
|
| 'inline' puts the controls and the button in one input group, which suits a
| one-field signup; checkboxes and radio groups go underneath it. 'stacked' lays
| every field out on its own, with floating labels.
|
| The CSS and JS ship with the first form on the page: collect_css() and
| collect_js() de-duplicate by key, so no component has to carry them.
|
| Submission is core's: core/modules/forms/submit.php validates the declared fields,
| stores the submission and answers JSON. The script below posts with fetch and
| refreshes an expired token once, because a cached page can outlive it.
|
*/

$formType   = (string) ($form['type'] ?? '');
$formConfig = theme_config()['form_types'][$formType] ?? [];

if ($formType === '' || !$formConfig) {
    return;
}

$formFields  = $formConfig['fields'] ?? [];
$formVariant = ($form['variant'] ?? 'stacked') === 'inline' ? 'inline' : 'stacked';
$formId      = (string) ($form['id'] ?? uniqid('theme-form-'));
$formInline  = $formVariant === 'inline';

collect_css($collectedCss, 'theme-form', <<<'CSS'
.theme-form .special-field { position: absolute; left: -9999px; top: -9999px; height: 0; overflow: hidden; }
.theme-form .message { margin-top: 1rem; display: none; }
.theme-form .message.success { color: #2e7d32; }
.theme-form .message.error { color: #c62828; }
/* An inline form sits on a coloured panel, so its feedback needs light text. */
.theme-form--inline .message.success { color: #d1e7dd; }
.theme-form--inline .message.error { color: #ffd7d7; }
CSS);

collect_js($collectedJs, 'theme-form', <<<'JS'
document.addEventListener('submit', function(e){
    const form = e.target.closest('form.theme-form');
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
JS);

/** The attributes and label every field shares. */
$formField = static function (array $cfg, string $name, string $formId): array {
    $type = (string) ($cfg['type'] ?? 'text');

    return [
        'type'     => $type,
        'name'     => (string) $name,
        'id'       => $formId . '-' . $name,
        'label'    => (string) ($cfg['label'] ?? ucfirst(str_replace('_', ' ', (string) $name))),
        'required' => !empty($cfg['required']) ? 'required' : '',
        'options'  => is_array($cfg['options'] ?? null) ? $cfg['options'] : [],
    ];
};

?>
<form class="theme-form theme-form--<?= e($formVariant) ?>" action="<?= url('form-submit') ?>" method="post"
      data-success="<?= e((string) ($form['success'] ?? '')) ?>"
      data-error="<?= e((string) ($form['error'] ?? '')) ?>">

    <input type="hidden" name="form_type" value="<?= e($formType) ?>">
    <?= form_token_field($formType) ?>

    <?php if (!empty($form['page_id'])): ?>
        <input type="hidden" name="page_id" value="<?= (int) $form['page_id'] ?>">
    <?php endif; ?>

    <?php if (!empty($form['redirect'])): ?>
        <input type="hidden" name="redirect_url" value="<?= e((string) $form['redirect']) ?>">
    <?php endif; ?>

    <!-- Honeypot -->
    <label class="special-field">
        Company
        <input type="text" name="company" tabindex="-1" autocomplete="off">
    </label>

    <?php if ($formInline): ?>
        <div class="input-group mb-2">
            <?php foreach ($formFields as $name => $cfg): ?>
                <?php $field = $formField($cfg, (string) $name, $formId); ?>
                <?php if ($field['type'] === 'checkbox' || $field['type'] === 'radio'): continue; endif; ?>

                <?php if ($field['type'] === 'select'): ?>
                    <select class="form-control" id="<?= e($field['id']) ?>" name="<?= e($field['name']) ?>" aria-label="<?= e($field['label']) ?>" <?= $field['required'] ?>>
                        <option value=""></option>
                        <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                            <option value="<?= e((string) $optionValue) ?>"><?= e((string) $optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($field['type'] === 'textarea'): ?>
                    <textarea class="form-control" id="<?= e($field['id']) ?>" name="<?= e($field['name']) ?>" placeholder="<?= e($field['label']) ?>" aria-label="<?= e($field['label']) ?>" <?= $field['required'] ?>></textarea>
                <?php else: ?>
                    <input class="form-control" id="<?= e($field['id']) ?>" type="<?= e($field['type']) ?>" name="<?= e($field['name']) ?>" placeholder="<?= e($field['label']) ?>" aria-label="<?= e($field['label']) ?>" <?= $field['required'] ?> />
                <?php endif; ?>
            <?php endforeach; ?>

            <button class="btn btn-outline-light" type="submit"><?= e((string) ($form['submit'] ?? 'Send')) ?></button>
        </div>
    <?php endif; ?>

    <?php foreach ($formFields as $name => $cfg): ?>
        <?php $field = $formField($cfg, (string) $name, $formId); ?>

        <?php if ($field['type'] === 'checkbox'): ?>
            <div class="form-check <?= $formInline ? 'small text-white-50' : 'mb-3' ?>">
                <input class="form-check-input" id="<?= e($field['id']) ?>" type="checkbox" name="<?= e($field['name']) ?>" <?= $field['required'] ?> />
                <label class="form-check-label" for="<?= e($field['id']) ?>"><?= e($field['label']) ?></label>
            </div>
        <?php elseif ($field['type'] === 'radio'): ?>
            <fieldset class="mb-3">
                <legend class="form-check-label"><?= e($field['label']) ?></legend>
                <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                    <?php $optionId = $field['id'] . '-' . $optionValue; ?>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" id="<?= e($optionId) ?>" name="<?= e($field['name']) ?>" value="<?= e((string) $optionValue) ?>" <?= $field['required'] ?> />
                        <label class="form-check-label" for="<?= e($optionId) ?>"><?= e((string) $optionLabel) ?></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        <?php elseif ($formInline): ?>
            <?php /* Already in the input group above. */ ?>
        <?php elseif ($field['type'] === 'select'): ?>
            <div class="form-floating mb-3">
                <select class="form-control" id="<?= e($field['id']) ?>" name="<?= e($field['name']) ?>" <?= $field['required'] ?>>
                    <option value=""></option>
                    <?php foreach ($field['options'] as $optionValue => $optionLabel): ?>
                        <option value="<?= e((string) $optionValue) ?>"><?= e((string) $optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="<?= e($field['id']) ?>"><?= e($field['label']) ?></label>
            </div>
        <?php else: ?>
            <div class="form-floating mb-3">
                <?php if ($field['type'] === 'textarea'): ?>
                    <?php /* The label floats into the field, so the placeholder stays a blank. */ ?>
                    <textarea class="form-control" id="<?= e($field['id']) ?>" name="<?= e($field['name']) ?>" placeholder=" " style="height: 10rem" <?= $field['required'] ?>></textarea>
                <?php else: ?>
                    <input class="form-control" id="<?= e($field['id']) ?>" type="<?= e($field['type']) ?>" name="<?= e($field['name']) ?>" placeholder=" " <?= $field['required'] ?> />
                <?php endif; ?>
                <label for="<?= e($field['id']) ?>"><?= e($field['label']) ?></label>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if (!$formInline): ?>
        <div class="d-grid">
            <button class="btn btn-primary btn-lg" type="submit"><?= e((string) ($form['submit'] ?? 'Send')) ?></button>
        </div>
    <?php endif; ?>

    <div class="message" role="status"></div>
</form>

<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
|
| Small, explicit helpers rather than a schema engine. Each function returns
| true/false (or a normalised value) so call sites stay readable:
|
|   $errors = [];
|   if (!validate_slug($slug)) { $errors['slug'] = 'Invalid slug.'; }
|   ...
|   validate_throw($errors);   // redirect/toast or 422 for JSON callers
|
*/


/**
 * Abort when validation failed. JSON callers get a 422 payload; admin form
 * posts get a toast and a redirect back.
 */
function validate_throw(array $errors, string $redirectPath = 'dashboard'): void
{
    if (!$errors) {
        return;
    }

    $message = implode(' ', array_values($errors));

    if (request_wants_json()) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Validation failed', 'fields' => $errors]);
        exit;
    }

    if (function_exists('redirect_with_toast')) {
        redirect_with_toast($redirectPath, 'error', $message);
    }

    http_response_code(422);
    exit($message);
}

/*
|--------------------------------------------------------------------------
| Individual rules
|--------------------------------------------------------------------------
*/

function validate_required(mixed $value): bool
{
    if (is_array($value)) {
        return $value !== [];
    }

    return trim((string) $value) !== '';
}

function validate_slug(string $slug, int $maxLength = 160): bool
{
    if ($slug === '' || strlen($slug) > $maxLength) {
        return false;
    }

    // Same shape the front-end router accepts.
    return (bool) preg_match('/^[a-z0-9\-]+$/', $slug);
}

function validate_url_prefix(string $prefix): bool
{
    if ($prefix === '') {
        return true;
    }

    return (bool) preg_match('#^[a-z0-9\-]+(/[a-z0-9\-]+)*$#', $prefix) && strlen($prefix) <= 120;
}

function validate_email(string $email, bool $allowEmpty = false): bool
{
    if ($email === '') {
        return $allowEmpty;
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * A comma-separated list of email addresses. Blank is allowed when $allowEmpty.
 */
function validate_email_list(string $value, bool $allowEmpty = true): bool
{
    $value = trim($value);

    if ($value === '') {
        return $allowEmpty;
    }

    foreach (preg_split('/\s*,\s*/', $value) ?: [] as $email) {
        if (!validate_email($email)) {
            return false;
        }
    }

    return true;
}

/**
 * A media id, an absolute URL, or a theme asset filename.
 *
 * The shape every image setting accepts, so a favicon can point at a media id
 * while a theme keeps shipping a plain filename.
 */
function validate_image_reference(string $value, bool $allowEmpty = true): bool
{
    $value = trim($value);

    if ($value === '') {
        return $allowEmpty;
    }

    if (ctype_digit($value)) {
        return true;
    }

    if (validate_url($value)) {
        return true;
    }

    return (bool) preg_match('#^[a-z0-9._\-/]+\.(jpe?g|png|gif|webp|avif|svg|ico)$#i', $value);
}

function validate_username(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9._\-]{3,32}$/', strtolower($username));
}

/**
 * Two-letter language codes, optionally with a region (sv, en-GB).
 */
function validate_language_code(string $code): bool
{
    return (bool) preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code);
}

function validate_enum(mixed $value, array $allowed): bool
{
    return in_array($value, $allowed, true);
}

function validate_int_range(mixed $value, int $min, int $max): bool
{
    if (!is_numeric($value)) {
        return false;
    }

    $int = (int) $value;

    return $int >= $min && $int <= $max;
}

/**
 * Absolute http(s) URL.
 */
function validate_url(string $url, bool $allowEmpty = false): bool
{
    if ($url === '') {
        return $allowEmpty;
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    return (bool) preg_match('#^https?://#i', $url);
}

/**
 * Parse and sanity-check a comma separated list of image widths.
 *
 * @return int[]|null null when the input is invalid
 */
function validate_sizes_csv(mixed $value): ?array
{
    $parts = is_array($value) ? $value : explode(',', (string) $value);

    $sizes = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }

        if (!ctype_digit($part)) {
            return null;
        }

        $size = (int) $part;
        if ($size < 16 || $size > 4000) {
            return null;
        }

        $sizes[] = $size;
    }

    $sizes = array_values(array_unique($sizes));

    if (!$sizes || count($sizes) > 12) {
        return null;
    }

    sort($sizes);

    return $sizes;
}

/**
 * Normalise a datetime-local value ("2026-03-01T09:30") from the site
 * timezone into a UTC timestamp. Returns null when empty or unparseable.
 */
function validate_local_datetime(mixed $value, string $timezone = 'UTC'): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    try {
        $dt = new DateTime($value, new DateTimeZone($timezone));
    } catch (Throwable $exception) {
        return null;
    }

    return $dt->getTimestamp();
}

/**
 * Is a timestamp strictly in the future?
 */
function validate_future_timestamp(?int $timestamp): bool
{
    return $timestamp !== null && $timestamp > time();
}

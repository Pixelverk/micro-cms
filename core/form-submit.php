<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// --------------------------------------------------
// Honeypot (bots)
// --------------------------------------------------
if (!empty($_POST['company'])) {
    // Pretend success to confuse bots
    echo json_encode(['success' => true]);
    exit;
}

// --------------------------------------------------
// Load config
// --------------------------------------------------
$theme     = theme_config();
$formTypes = $theme['form_types'] ?? [];

$formType = $_POST['form_type'] ?? null;

if (!$formType || !isset($formTypes[$formType])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid form type']);
    exit;
}

// --------------------------------------------------
// Signed token (cache-safe CSRF for public forms)
// --------------------------------------------------
$submittedToken = $_POST['_form_token'] ?? null;

if (!form_token_check((string) $formType, is_string($submittedToken) ? $submittedToken : null)) {
    http_response_code(419);
    echo json_encode([
        'error' => 'That form has expired. Reloading the page should fix it.',
        'stale' => true,
    ]);
    exit;
}

// --------------------------------------------------
// Simple per-IP rate limit
// --------------------------------------------------
if (!form_rate_limit_ok((string) $formType)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many submissions. Please try again later.']);
    exit;
}

$formConfig = $formTypes[$formType];
$fields     = $formConfig['fields'] ?? [];

if (!$fields) {
    http_response_code(500);
    echo json_encode(['error' => 'Form has no fields configured']);
    exit;
}

// --------------------------------------------------
// Validate fields
// --------------------------------------------------
$data = [];
$errors = [];

foreach ($fields as $field => $rules) {
    $value = trim($_POST[$field] ?? '');

    if (($rules['required'] ?? false) && $value === '') {
        $errors[$field] = 'Required';
        continue;
    }

    if (($rules['email'] ?? false) && $value !== '') {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $errors[$field] = 'Invalid email';
            continue;
        }
    }

    $data[$field] = $value;
}

if ($errors) {
    http_response_code(422);
    echo json_encode([
        'error'  => 'Validation failed',
        'fields' => $errors,
    ]);
    exit;
}

// --------------------------------------------------
// Store submission
// --------------------------------------------------
$pdo = db();
$now = time();

if (($formConfig['store_submission'] ?? true) === true) {

    $pageId = isset($_POST['page_id']) && is_numeric($_POST['page_id'])
        ? (int) $_POST['page_id']
        : null;

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO form_submissions (
            form_type,
            page_id,
            data,
            ip_address,
            user_agent,
            created_at,
            updated_at
        )
        VALUES (
            :form_type,
            :page_id,
            :data,
            :ip_address,
            :user_agent,
            :created_at,
            :updated_at
        )
    ");

    $stmt->execute([
        'form_type'  => $formType,
        'page_id'    => $pageId,
        'data'       => json_encode($data, JSON_THROW_ON_ERROR),
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

// --------------------------------------------------
// Email notification (optional)
// --------------------------------------------------
$settings = load_settings();
$env      = config('env') ?? 'production';

$sent = true;

$settingKey = $formConfig['notification_email_setting'] ?? null;
$to = $settingKey ? ($settings[$settingKey] ?? '') : '';

if ($to && filter_var($to, FILTER_VALIDATE_EMAIL)) {

    $subject = "New {$formConfig['label']} submission";

    $body = '';
    foreach ($data as $key => $value) {
        $body .= ucfirst($key) . ": {$value}\n";
    }

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
    ];

    // Prefer reply-to if email field exists
    if (!empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $data['email'];
        $headers[] = 'From: ' . $data['email'];
    }

    if ($env !== 'production') {
        // Log instead of send
        $log = [
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body,
            'headers' => $headers,
            'time'    => date('c'),
        ];

        file_put_contents(
            STORAGE_PATH . '/logs/forms.log',
            json_encode($log, JSON_PRETTY_PRINT) . "\n\n",
            FILE_APPEND
        );
    } else {
        $sent = mail($to, $subject, $body, implode("\r\n", $headers));
    }
}

if (!$sent) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send notification']);
    exit;
}

// --------------------------------------------------
// Success
// --------------------------------------------------
$redirect = $_POST['redirect_url'] ?? null;

$response = ['success' => true];

if ($redirect) {
    $response['redirect'] = $redirect;
}

echo json_encode($response);
exit;

/**
 * Cheap per-IP, per-form rate limit. Keeps public forms from being used as a
 * mail relay. Fails open when the table is unavailable.
 */
function form_rate_limit_ok(string $formType, int $maxPerHour = 12): bool
{
    try {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS form_rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                form_type TEXT NOT NULL,
                ip TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )
        ");

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        $cutoff = time() - 3600;

        $count = $pdo->prepare("
            SELECT COUNT(*) FROM form_rate_limits
            WHERE form_type = :form_type AND ip = :ip AND created_at > :cutoff
        ");
        $count->execute(['form_type' => $formType, 'ip' => $ip, 'cutoff' => $cutoff]);

        if ((int) $count->fetchColumn() >= $maxPerHour) {
            return false;
        }

        $insert = $pdo->prepare("
            INSERT INTO form_rate_limits (form_type, ip, created_at)
            VALUES (:form_type, :ip, :now)
        ");
        $insert->execute(['form_type' => $formType, 'ip' => $ip, 'now' => time()]);

        // Opportunistic cleanup
        if (random_int(1, 20) === 1) {
            $pdo->prepare("DELETE FROM form_rate_limits WHERE created_at < :cutoff")
                ->execute(['cutoff' => time() - 86400]);
        }

        return true;
    } catch (Throwable $exception) {
        return true;
    }
}
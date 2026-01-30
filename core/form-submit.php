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
echo json_encode(['success' => true]);
exit;
<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$settings = load_settings();
$to = $settings['contact_email'] ?? '';

if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    http_response_code(500);
    echo json_encode(['error' => 'Contact email not configured']);
    exit;
}

// Basic fields (extend later)
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    http_response_code(422);
    echo json_encode(['error' => 'All fields are required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid email address']);
    exit;
}

// Build email
$subject = "New contact form message from {$name}";
$body = <<<TXT
Name: {$name}
Email: {$email}

Message:
{$message}
TXT;

$headers = [
    'From: ' . $email,
    'Reply-To: ' . $email,
    'Content-Type: text/plain; charset=UTF-8',
];

$sent = false;

// Local Mode: log instead of send
$env = config('env');

if ($env !== 'production') {
    $log = [
        'to'      => $to,
        'subject' => $subject,
        'body'    => $body,
        'headers' => $headers,
        'time'    => date('c'),
    ];

    file_put_contents(
        STORAGE_PATH . '/logs/contact.log',
        json_encode($log, JSON_PRETTY_PRINT) . "\n\n",
        FILE_APPEND
    );

    $sent = true;
} else {
    $sent = mail($to, $subject, $body, implode("\r\n", $headers));
}

if (!$sent) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message']);
    exit;
}

echo json_encode(['success' => true]);
exit;
<?php
declare(strict_types=1);

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');

function respond_json(bool $success, string $message, string $downloadUrl = ''): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'download_url' => $downloadUrl,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect_back(string $status, bool $download = false, string $plan = ''): void
{
    $url = 'growth-plan-download.html?status=' . rawurlencode($status);
    if ($download) {
        $url .= '&download=1';
    }
    if ($plan !== '') {
        $url .= '&plan=' . rawurlencode($plan);
    }
    header('Location: ' . $url, true, 303);
    exit;
}

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if ($isAjax) {
        respond_json(false, 'Invalid request method.');
    }
    redirect_back('invalid');
}

// Honeypot check for bots
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    if ($isAjax) {
        respond_json(true, 'Success', 'assets/docs/Dealifly-Growth-Plan.pdf');
    }
    redirect_back('success', true);
}

$clean = static function ($value, int $maximumLength): string {
    $value = trim(strip_tags((string) $value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maximumLength)
        : substr($value, 0, $maximumLength);
};

$company = $clean($_POST['company'] ?? '', 150);
$firstName = $clean($_POST['first_name'] ?? '', 80);
$lastName = $clean($_POST['last_name'] ?? '', 80);
$email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$phone = $clean($_POST['phone'] ?? '', 40);
$country = $clean($_POST['country'] ?? '', 80);
$comment = $clean($_POST['comment'] ?? '', 2000);
$plan = $clean($_POST['plan'] ?? 'General Growth Plan', 80);
$privacy = isset($_POST['privacy']) && ($_POST['privacy'] === '1' || $_POST['privacy'] === 'on');

if ($firstName === '' || $lastName === '' || $email === false || $country === '' || !$privacy) {
    if ($isAjax) {
        respond_json(false, 'Please fill in all required fields and accept the privacy policy.');
    }
    redirect_back('invalid', false, $plan);
}

// 1. Persist lead to assets/data/growth-plan-leads.json so no lead is ever lost
$leadData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'first_name' => $firstName,
    'last_name' => $lastName,
    'email' => $email,
    'company' => $company !== '' ? $company : 'Not provided',
    'phone' => $phone !== '' ? $phone : 'Not provided',
    'country' => $country,
    'comment' => $comment !== '' ? $comment : 'None',
    'plan' => $plan,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
];

$dataFile = __DIR__ . '/assets/data/growth-plan-leads.json';
$existingLeads = [];
if (file_exists($dataFile)) {
    $content = @file_get_contents($dataFile);
    if ($content !== false && $content !== '') {
        $decoded = @json_decode($content, true);
        if (is_array($decoded)) {
            $existingLeads = $decoded;
        }
    }
}
$existingLeads[] = $leadData;
@file_put_contents($dataFile, json_encode($existingLeads, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

// 2. Email notification to contact@dealifly.com
$recipient = 'contact@dealifly.com';
$subject = 'Growth Plan Download: ' . $firstName . ' ' . $lastName . ($company !== '' ? ' (' . $company . ')' : '');
$body = implode("\n", [
    'New Growth Plan Download Request from Dealifly Website',
    '=====================================================',
    'Plan Requested: ' . $plan,
    'Date & Time:    ' . date('Y-m-d H:i:s T'),
    '',
    'Contact Information:',
    '--------------------',
    'First Name:     ' . $firstName,
    'Last Name:      ' . $lastName,
    'Email:          ' . $email,
    'Company:        ' . ($company !== '' ? $company : 'Not provided'),
    'Phone:          ' . ($phone !== '' ? $phone : 'Not provided'),
    'Country:        ' . $country,
    '',
    'Request / Comment:',
    '------------------',
    $comment !== '' ? $comment : 'None',
    '',
    'Privacy Policy: Accepted',
]);

$headers = implode("\r\n", [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: Dealifly Website <contact@dealifly.com>',
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . PHP_VERSION,
]);

@mail($recipient, $subject, wordwrap($body, 78), $headers);

$downloadUrl = 'assets/docs/Dealifly-Growth-Plan.pdf';

if ($isAjax) {
    respond_json(true, 'Success', $downloadUrl);
}

redirect_back('success', true, $plan);

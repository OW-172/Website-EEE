<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, bool $success, string $message): never {
    http_response_code($status);
    echo json_encode(
        ['success' => $success, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, false, 'Diese Anfrage ist nicht zulässig.');
}

$secret = trim((string) getenv('TURNSTILE_SECRET_KEY'));
$secretFile = __DIR__ . '/private/turnstile-secret.php';

if ($secret === '' && is_file($secretFile)) {
    $loadedSecret = require $secretFile;
    if (is_string($loadedSecret)) {
        $secret = trim($loadedSecret);
    }
}

if ($secret === '' || str_contains($secret, 'HIER_')) {
    error_log('TALUS Kontaktformular: Cloudflare Turnstile Secret Key fehlt.');
    respond(503, false, 'Das Kontaktformular ist noch nicht vollständig eingerichtet. Bitte kontaktieren Sie uns telefonisch oder per E-Mail.');
}

$name = trim((string) ($_POST['name'] ?? ''));
$telefon = trim((string) ($_POST['telefon'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$ort = trim((string) ($_POST['ort'] ?? ''));
$gebaeudeart = trim((string) ($_POST['gebaeudeart'] ?? ''));
$thema = trim((string) ($_POST['thema'] ?? ''));
$nachricht = trim((string) ($_POST['nachricht'] ?? ''));
$turnstileToken = trim((string) ($_POST['cf-turnstile-response'] ?? ''));

if ($name === '' || $telefon === '' || $email === '') {
    respond(422, false, 'Bitte füllen Sie Name, Telefon und E-Mail vollständig aus.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
    respond(422, false, 'Bitte geben Sie eine gültige E-Mail-Adresse ein.');
}

if (
    mb_strlen($name) > 120 ||
    mb_strlen($telefon) > 60 ||
    mb_strlen($email) > 160 ||
    mb_strlen($ort) > 120 ||
    mb_strlen($nachricht) > 4000
) {
    respond(422, false, 'Eine Eingabe ist zu lang. Bitte kürzen Sie Ihre Angaben.');
}

if ($turnstileToken === '') {
    respond(422, false, 'Bitte bestätigen Sie die Sicherheitsprüfung und senden Sie das Formular erneut.');
}

$verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
$verifyData = http_build_query([
    'secret' => $secret,
    'response' => $turnstileToken,
]);

$verifyBody = false;
$verifyStatus = 0;

if (function_exists('curl_init')) {
    $ch = curl_init($verifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $verifyData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $verifyBody = curl_exec($ch);
    $verifyStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
} elseif ((bool) ini_get('allow_url_fopen')) {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $verifyData,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $verifyBody = @file_get_contents($verifyUrl, false, $context);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $verifyStatus = (int) $m[1];
    }
} else {
    error_log('TALUS Kontaktformular: Weder cURL noch allow_url_fopen verfügbar.');
    respond(500, false, 'Die Sicherheitsprüfung konnte technisch nicht ausgeführt werden. Bitte versuchen Sie es später erneut.');
}

if ($verifyBody === false || $verifyStatus < 200 || $verifyStatus >= 300) {
    error_log('TALUS Kontaktformular: Turnstile Siteverify nicht erreichbar, HTTP ' . $verifyStatus);
    respond(502, false, 'Die Sicherheitsprüfung ist derzeit nicht erreichbar. Bitte versuchen Sie es später erneut.');
}

$verification = json_decode((string) $verifyBody, true);
if (!is_array($verification) || empty($verification['success'])) {
    respond(422, false, 'Die Sicherheitsprüfung war nicht erfolgreich. Bitte versuchen Sie es erneut.');
}

$hostname = (string) ($verification['hostname'] ?? '');
$allowedHostnames = ['talus-eb.de', 'www.talus-eb.de'];
if ($hostname !== '' && !in_array($hostname, $allowedHostnames, true)) {
    error_log('TALUS Kontaktformular: Unerwarteter Turnstile-Hostname: ' . $hostname);
    respond(422, false, 'Die Sicherheitsprüfung konnte nicht bestätigt werden.');
}

$action = (string) ($verification['action'] ?? '');
if ($action !== '' && $action !== 'kontaktformular') {
    error_log('TALUS Kontaktformular: Unerwartete Turnstile-Action: ' . $action);
    respond(422, false, 'Die Sicherheitsprüfung konnte nicht bestätigt werden.');
}

$recipient = 'info@talus-eb.de';
$subject = 'Neue Website-Anfrage: ' . ($thema !== '' ? $thema : 'Energieberatung');

$body = implode("\r\n", [
    'Neue Anfrage über talus-eb.de',
    '',
    'Name: ' . $name,
    'Telefon: ' . $telefon,
    'E-Mail: ' . $email,
    'PLZ / Ort: ' . ($ort !== '' ? $ort : '–'),
    'Gebäudeart: ' . ($gebaeudeart !== '' ? $gebaeudeart : '–'),
    'Thema: ' . ($thema !== '' ? $thema : '–'),
    '',
    'Nachricht:',
    $nachricht !== '' ? $nachricht : '–',
]);

$headers = implode("\r\n", [
    'From: TALUS Website <info@talus-eb.de>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

if (!mail($recipient, $encodedSubject, $body, $headers)) {
    error_log('TALUS Kontaktformular: PHP mail() konnte die Nachricht nicht übergeben.');
    respond(500, false, 'Die Nachricht konnte technisch nicht versendet werden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.');
}

respond(200, true, 'Danke! Ihre Anfrage wurde erfolgreich versendet.');

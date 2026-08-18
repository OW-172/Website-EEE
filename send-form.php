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

function httpPost(
    string $url,
    string $body,
    array $headers,
    int $timeout = 15
): array {
    $responseBody = false;
    $status = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            error_log('TALUS Kontaktformular: HTTP-Fehler: ' . curl_error($ch));
        }

        curl_close($ch);
    } elseif ((bool) ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);

        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
    } else {
        error_log('TALUS Kontaktformular: Weder cURL noch allow_url_fopen verfügbar.');
    }

    return [$status, $responseBody];
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

[$verifyStatus, $verifyBody] = httpPost(
    $verifyUrl,
    $verifyData,
    ['Content-Type: application/x-www-form-urlencoded'],
    10
);

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

$graphConfigFile = __DIR__ . '/private/microsoft-graph.php';

if (!is_file($graphConfigFile)) {
    error_log('TALUS Kontaktformular: Microsoft-Graph-Konfiguration fehlt.');
    respond(503, false, 'Der E-Mail-Versand ist noch nicht vollständig eingerichtet. Bitte kontaktieren Sie uns telefonisch oder per E-Mail.');
}

$graphConfig = require $graphConfigFile;

if (!is_array($graphConfig)) {
    error_log('TALUS Kontaktformular: Microsoft-Graph-Konfiguration ist ungültig.');
    respond(503, false, 'Der E-Mail-Versand ist noch nicht vollständig eingerichtet. Bitte kontaktieren Sie uns telefonisch oder per E-Mail.');
}

$tenantId = trim((string) ($graphConfig['tenant_id'] ?? ''));
$clientId = trim((string) ($graphConfig['client_id'] ?? ''));
$clientSecret = trim((string) ($graphConfig['client_secret'] ?? ''));
$sender = trim((string) ($graphConfig['sender'] ?? 'info@talus-eb.de'));

if (
    $tenantId === '' ||
    $clientId === '' ||
    $clientSecret === '' ||
    $sender === '' ||
    str_contains($clientSecret, 'HIER_') ||
    !filter_var($sender, FILTER_VALIDATE_EMAIL)
) {
    error_log('TALUS Kontaktformular: Microsoft-Graph-Konfiguration unvollständig.');
    respond(503, false, 'Der E-Mail-Versand ist noch nicht vollständig eingerichtet. Bitte kontaktieren Sie uns telefonisch oder per E-Mail.');
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

$tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token';
$tokenData = http_build_query([
    'client_id' => $clientId,
    'scope' => 'https://graph.microsoft.com/.default',
    'client_secret' => $clientSecret,
    'grant_type' => 'client_credentials',
]);

[$tokenStatus, $tokenBody] = httpPost(
    $tokenUrl,
    $tokenData,
    ['Content-Type: application/x-www-form-urlencoded'],
    15
);

$tokenResponse = is_string($tokenBody) ? json_decode($tokenBody, true) : null;
$accessToken = is_array($tokenResponse) ? trim((string) ($tokenResponse['access_token'] ?? '')) : '';

if ($tokenStatus < 200 || $tokenStatus >= 300 || $accessToken === '') {
    error_log('TALUS Kontaktformular: Microsoft Graph Token konnte nicht abgerufen werden, HTTP ' . $tokenStatus);
    respond(502, false, 'Die Nachricht konnte technisch nicht versendet werden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.');
}

$mailPayload = json_encode([
    'message' => [
        'subject' => $subject,
        'body' => [
            'contentType' => 'Text',
            'content' => $body,
        ],
        'toRecipients' => [
            [
                'emailAddress' => [
                    'address' => $recipient,
                ],
            ],
        ],
        'replyTo' => [
            [
                'emailAddress' => [
                    'name' => $name,
                    'address' => $email,
                ],
            ],
        ],
    ],
    'saveToSentItems' => true,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($mailPayload === false) {
    error_log('TALUS Kontaktformular: Microsoft-Graph-Nachricht konnte nicht als JSON erzeugt werden.');
    respond(500, false, 'Die Nachricht konnte technisch nicht versendet werden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.');
}

$sendUrl = 'https://graph.microsoft.com/v1.0/users/' . rawurlencode($sender) . '/sendMail';

[$sendStatus, $sendBody] = httpPost(
    $sendUrl,
    $mailPayload,
    [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ],
    20
);

if ($sendStatus !== 202) {
    $graphError = '';
    if (is_string($sendBody) && $sendBody !== '') {
        $decoded = json_decode($sendBody, true);
        if (is_array($decoded)) {
            $graphError = (string) ($decoded['error']['code'] ?? '');
        }
    }

    error_log(
        'TALUS Kontaktformular: Microsoft Graph sendMail fehlgeschlagen, HTTP ' .
        $sendStatus .
        ($graphError !== '' ? ', Code ' . $graphError : '')
    );

    respond(500, false, 'Die Nachricht konnte technisch nicht versendet werden. Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.');
}

respond(200, true, 'Danke! Ihre Anfrage wurde erfolgreich versendet.');

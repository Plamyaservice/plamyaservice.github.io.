<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond(int $status, bool $ok, string $message): never
{
    http_response_code($status);
    echo json_encode(
        ['ok' => $ok, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Метод не поддерживается');
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    $data = $_POST;
}

$name = trim((string)($data['name'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));
$service = trim((string)($data['service'] ?? ''));

if ($name === '' || $phone === '' || $service === '') {
    respond(422, false, 'Заполните все поля');
}

if (mb_strlen($name) > 80 || mb_strlen($phone) > 30 || mb_strlen($service) > 120) {
    respond(422, false, 'Слишком длинные данные');
}

if (!preg_match('/^[0-9+()\-\s]{6,30}$/u', $phone)) {
    respond(422, false, 'Проверьте номер телефона');
}


$botToken = '8191574235:AAGCaYoygszr9-BLHF2VW2FYlLSvAW_nl7c';
$chatId = '1033766424';

$text = "📌 Новая заявка!\n\n"
    . "🔹 Имя: {$name}\n"
    . "🔹 Телефон: {$phone}\n"
    . "🔹 Услуга: {$service}";

$endpoint = "https://api.telegram.org/bot{$botToken}/sendMessage";
$postFields = [
    'chat_id' => $chatId,
    'text' => $text,
    'disable_web_page_preview' => 'true',
];

$responseBody = false;
$httpCode = 0;
$errorMessage = '';

if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postFields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);

    $responseBody = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($responseBody === false) {
        $errorMessage = curl_error($ch);
    }

    curl_close($ch);
} else {
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($postFields),
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($endpoint, false, $context);

    if (isset($http_response_header[0])
        && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
        $httpCode = (int)$matches[1];
    }

    if ($responseBody === false) {
        $errorMessage = 'Не удалось подключиться к Telegram API';
    }
}

$telegramResponse = is_string($responseBody)
    ? json_decode($responseBody, true)
    : null;

if ($httpCode !== 200 || !is_array($telegramResponse) || ($telegramResponse['ok'] ?? false) !== true) {
    error_log(
        'Telegram form error: HTTP ' . $httpCode
        . '; transport=' . $errorMessage
        . '; response=' . (is_string($responseBody) ? $responseBody : 'false')
    );

    respond(502, false, 'Telegram временно недоступен');
}

respond(200, true, 'Заявка отправлена');

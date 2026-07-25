<?php
// api/realtime_token.php

header('Content-Type: application/json; charset=utf-8');

// ローカル検証用。必要なければ本番では削除OK。
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_exit(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$configPath = __DIR__ . '/../config/openai.php';

if (!file_exists($configPath)) {
    json_exit([
        'ok' => false,
        'error' => 'config/openai.php が見つかりません。',
        'debug' => [
            'expected_path' => $configPath
        ]
    ], 500);
}

require_once $configPath;

$apiKey = defined('OPENAI_API_KEY') ? trim((string)OPENAI_API_KEY) : '';

if (
    $apiKey === '' ||
    str_contains($apiKey, 'YOUR_OPENAI_API_KEY') ||
    !str_starts_with($apiKey, 'sk' . '-')
) {
    json_exit([
        'ok' => false,
        'error' => 'サーバー設定に問題があります。管理者にお問い合わせください。',
    ], 500);
}

$model = defined('OPENAI_REALTIME_MODEL')
    ? trim((string)OPENAI_REALTIME_MODEL)
    : 'gpt-realtime-2.1-mini';

$voice = defined('OPENAI_REALTIME_VOICE')
    ? trim((string)OPENAI_REALTIME_VOICE)
    : 'marin';

if ($model === '') {
    $model = 'gpt-realtime-2.1-mini';
}

if ($voice === '') {
    $voice = 'marin';
}

$instructions = defined('OPENAI_REALTIME_INSTRUCTIONS')
    ? trim((string)OPENAI_REALTIME_INSTRUCTIONS)
    : <<<TXT
あなたは「きずなちゃん」という、高齢者に寄り添うやさしい日本語の会話相手です。

会話ルール：
- 日本語で話してください。
- 返答は短く、原則1文、長くても2文にしてください。
- 質問ばかりで終わらないでください。
- カウンセラーのように大げさに共感しすぎないでください。
- 「話してくれてありがとう」「あなたの話は大切です」を多用しないでください。
- 高齢者が聞き取りやすい、落ち着いた自然な話し方にしてください。
- 医療診断や断定はしないでください。
- 体調不良や危険が疑われる場合は、家族や医療機関への相談をやさしく促してください。
TXT;

/**
 * 現行Realtime API：
 * /v1/realtime/client_secrets で短命のクライアントシークレットを作成する。
 *
 * 戻り値は data.value に ek_... 形式で入る。
 */
$payload = [
    'expires_after' => [
        'anchor' => 'created_at',
        'seconds' => 600
    ],
    'session' => [
        'type' => 'realtime',
        'model' => $model,
        'instructions' => $instructions,
        'output_modalities' => ['audio'],
        'audio' => [
            'input' => [
                'turn_detection' => [
                    'type' => 'server_vad',
                    'create_response' => true,
                    'interrupt_response' => true,
                    'prefix_padding_ms' => 300,
                    'silence_duration_ms' => 700,
                    'threshold' => 0.5
                ],
                'transcription' => [
                    'model' => 'gpt-4o-mini-transcribe',
                    'language' => 'ja'
                ]
            ],
            'output' => [
                'voice' => $voice
            ]
        ],
        'max_output_tokens' => 500
    ]
];

$ch = curl_init('https://api.openai.com/v1/realtime/client_secrets');

if ($ch === false) {
    json_exit([
        'ok' => false,
        'error' => 'cURLの初期化に失敗しました。'
    ], 500);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError) {
    json_exit([
        'ok' => false,
        'error' => 'OpenAI APIへの接続に失敗しました。',
        'detail' => $curlError
    ], 500);
}

$data = json_decode($response, true);

if ($httpCode < 200 || $httpCode >= 300) {
    json_exit([
        'ok' => false,
        'error' => 'OpenAI API error',
        'http_code' => $httpCode,
        'response' => $data ?: $response,
        'request' => [
            'model' => $model,
            'voice' => $voice
        ]
    ], 500);
}

$clientSecret = $data['value'] ?? null;

if (!$clientSecret) {
    json_exit([
        'ok' => false,
        'error' => 'Realtime用の一時キーを取得できませんでした。',
        'response' => $data
    ], 500);
}

/**
 * 既存の assets/realtime.js が
 * data.client_secret.value
 * を読んでいても動くように、互換形式で返す。
 */
json_exit([
    'ok' => true,
    'client_secret' => [
        'value' => $clientSecret,
        'expires_at' => $data['expires_at'] ?? null
    ],
    'ephemeral_key' => $clientSecret,
    'model' => $data['session']['model'] ?? $model,
    'voice' => $voice,
    'session_id' => $data['session']['id'] ?? null
]);
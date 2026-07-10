<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$configPath = __DIR__ . '/../config/openai.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'GETでアクセスしてください。'], 405);
}

$apiKey = '';
if (defined('OPENAI_API_KEY')) {
    $apiKey = trim((string)OPENAI_API_KEY);
}
if ($apiKey === '') {
    $envKey = getenv('OPENAI_API_KEY');
    if ($envKey !== false) {
        $apiKey = trim((string)$envKey);
    }
}

$apiKey = trim($apiKey);

if (
    $apiKey === '' ||
    $apiKey === 'YOUR_OPENAI_API_KEY_HERE' ||
    strpos($apiKey, 'YOUR_') !== false ||
    strlen($apiKey) < 20
) {
    json_response([
        'ok' => false,
        'message' => 'OPENAI_API_KEY が正しく設定されていません。config/openai.php または config/config.php を確認してください。',
    ], 500);
}
    json_response([
        'ok' => false,
        'error' => 'OPENAI_API_KEY が正しく設定されていません。config/openai.php を確認してください。',
        'debug' => [
            'defined_OPENAI_API_KEY' => defined('OPENAI_API_KEY'),
            'key_prefix' => $apiKey !== '' ? substr($apiKey, 0, 7) : '',
            'key_length' => strlen($apiKey),
        ],
    ], 500);

if (!function_exists('curl_init')) {
    json_response(['ok' => false, 'error' => 'PHPのcURL拡張が有効ではありません。'], 500);
}

$personId = max(1, (int)($_GET['person_id'] ?? 1));
$pdo = db();

$stmt = $pdo->prepare('SELECT id, display_name, memo FROM conversation_people WHERE id = ?');
$stmt->execute([$personId]);
$person = $stmt->fetch();

if (!$person) {
    json_response(['ok' => false, 'error' => '会話者が見つかりません。'], 404);
}

$model = defined('OPENAI_REALTIME_MODEL')
    ? trim((string)OPENAI_REALTIME_MODEL)
    : (getenv('OPENAI_REALTIME_MODEL') ?: 'gpt-realtime');

$voice = defined('OPENAI_REALTIME_VOICE')
    ? trim((string)OPENAI_REALTIME_VOICE)
    : (getenv('OPENAI_REALTIME_VOICE') ?: 'marin');

$personName = (string)($person['display_name'] ?? '利用者');
$personMemo = trim((string)($person['memo'] ?? ''));

$instructions =
    "あなたは高齢者に寄り添う会話相手『きずなちゃん』です。" .
    "介護職員やカウンセラーではなく、近所のやさしい女性のように自然に話してください。" .
    "日本語で、短く、やわらかく、聞き取りやすく話してください。" .
    "声は女性的で、落ち着いた雰囲気にしてください。アニメ声や子どもっぽい声にはしないでください。" .
    "返答は原則1文、長くても2文まで。15〜45文字程度を目安にしてください。" .
    "毎回質問で終わらないでください。質問は必要な時だけ、4回に1回程度までにしてください。" .
    "短い相づちには、質問や感謝ではなく、自然な短い返事をしてください。" .
    "『お話ししてくれてありがとう』『あなたの話は大切です』『ずっと聞いていましたよ』は使わないでください。" .
    "『うんうん、そうだったんですね』を多用しないでください。" .
    "相手が話している途中で遮らないでください。少し間が空いても待ってください。" .
    "天気、ニュース、現在時刻など最新情報は、分からない場合は断定しないでください。" .
    "医療判断や診断はせず、体調不良や危険がありそうな場合は家族や専門家への相談を促してください。" .
    "会話者の名前: {$personName}。" .
    ($personMemo !== '' ? "会話者メモ: {$personMemo}" : "");

$payload = [
    'session' => [
        'type' => 'realtime',
        'model' => $model,
        'instructions' => $instructions,
        'max_output_tokens' => 500,
        'output_modalities' => ['audio'],
        'audio' => [
            'input' => [
                'noise_reduction' => [
                    'type' => 'near_field',
                ],
                'transcription' => [
                    'model' => 'gpt-4o-mini-transcribe',
                    'language' => 'ja',
                    'prompt' => '高齢者との日本語会話です。地名、人名、日常会話を自然に聞き取ってください。',
                ],
                'turn_detection' => [
                    'type' => 'server_vad',
                    'threshold' => 0.5,
                    'prefix_padding_ms' => 400,
                    'silence_duration_ms' => 900,
                    'create_response' => true,
                    'interrupt_response' => true,
                ],
            ],
            'output' => [
                'voice' => $voice,
            ],
        ],
    ],
];

$ch = curl_init('https://api.openai.com/v1/realtime/client_secrets');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
    'Authorization: Bearer ' . $apiKey,
    'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_DNS_CACHE_TIMEOUT => 0,
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
$statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($responseBody === false) {
    json_response([
        'ok' => false,
        'error' => 'OpenAIへの接続に失敗しました。',
        'curl_errno' => $curlErrno,
        'curl_error' => $curlError,
    ], 500);
}

$data = json_decode($responseBody, true);

if (!is_array($data)) {
    json_response([
        'ok' => false,
        'error' => 'OpenAIからJSONではない応答が返りました。',
        'http_status' => $statusCode,
        'raw_response' => mb_substr($responseBody, 0, 1000),
    ], 500);
}

if ($statusCode < 200 || $statusCode >= 300) {
    json_response([
        'ok' => false,
        'error' => 'Realtime用トークンの発行に失敗しました。',
        'http_status' => $statusCode,
        'openai_error' => $data,
    ], 500);
}

$secretValue = $data['value'] ?? ($data['client_secret']['value'] ?? null);

if (!$secretValue) {
    json_response([
        'ok' => false,
        'error' => 'OpenAIの応答にclient secret valueが含まれていません。',
        'http_status' => $statusCode,
        'openai_response' => $data,
    ], 500);
}

json_response([
    'ok' => true,
    'value' => $secretValue,
    'ephemeral_key' => $secretValue,
    'client_secret' => [
        'value' => $secretValue,
        'expires_at' => $data['expires_at'] ?? ($data['client_secret']['expires_at'] ?? null),
    ],
    'expires_at' => $data['expires_at'] ?? ($data['client_secret']['expires_at'] ?? null),
    'session' => $data['session'] ?? null,
    'model' => $model,
    'voice' => $voice,
]);

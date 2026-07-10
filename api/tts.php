<?php
// OpenAI TTS endpoint for local PHP MVP.
// If this endpoint fails, assets/app.js automatically falls back to browser speechSynthesis.

require_once __DIR__ . '/../config/helpers.php';

$openaiConfig = __DIR__ . '/../config/openai.php';
if (file_exists($openaiConfig)) {
    require_once $openaiConfig;
}

require_post();

$input = json_decode(file_get_contents('php://input'), true);
$text = trim((string)($input['text'] ?? ''));

if ($text === '') {
    header('Content-Type: application/json; charset=utf-8');
    json_response(['ok' => false, 'error' => '読み上げるテキストが空です。'], 400);
}

function kizuna_openai_api_key(): string
{
    if (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') {
        return (string)OPENAI_API_KEY;
    }

    $env = getenv('OPENAI_API_KEY');
    if ($env !== false && $env !== '') {
        return $env;
    }

    return '';
}

function kizuna_tts_voice(): string
{
    if (defined('OPENAI_TTS_VOICE') && OPENAI_TTS_VOICE !== '') {
        return (string)OPENAI_TTS_VOICE;
    }

    $env = getenv('OPENAI_TTS_VOICE');
    if ($env !== false && $env !== '') {
        return $env;
    }

    // 女性的でやわらかい印象に寄せたい場合の初期候補。
    // 合わなければ config/openai.php で coral / nova / shimmer / marin などを試してください。
    return 'coral';
}

$apiKey = kizuna_openai_api_key();

if ($apiKey === '') {
    header('Content-Type: application/json; charset=utf-8');
    json_response(['ok' => false, 'error' => 'OPENAI_API_KEY が設定されていません。'], 500);
}

$payload = [
    'model' => defined('OPENAI_TTS_MODEL') ? OPENAI_TTS_MODEL : 'gpt-4o-mini-tts',
    'voice' => kizuna_tts_voice(),
    'input' => mb_substr($text, 0, 600),
    'instructions' => defined('OPENAI_TTS_INSTRUCTIONS')
        ? OPENAI_TTS_INSTRUCTIONS
        : '日本語で読み上げてください。若い成人女性から中年女性くらいの、やわらかく落ち着いた印象の声。高齢者に近くで話しかけるように、少しゆっくり、あたたかく、自然な抑揚で。アニメ声や子どもっぽい声にはしないでください。',
    'response_format' => 'mp3',
];

$ch = curl_init('https://api.openai.com/v1/audio/speech');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 30,
]);

$audio = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($audio === false || $httpCode < 200 || $httpCode >= 300) {
    header('Content-Type: application/json; charset=utf-8');
    json_response([
        'ok' => false,
        'error' => $error !== '' ? $error : 'OpenAI TTS API の呼び出しに失敗しました。',
        'status' => $httpCode,
    ], 500);
}

header('Content-Type: audio/mpeg');
header('Content-Length: ' . strlen($audio));
header('Cache-Control: no-store');
echo $audio;
exit;

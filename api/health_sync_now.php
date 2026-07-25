<?php
// api/health_sync_now.php
// ローカル検証用：Google Health同期 + AIサマリ生成を手動実行するAPI。
// 本番公開時は、ログインチェック・家族権限チェック・CSRF対策・連打防止を必ず入れてください。

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function json_exit(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Local確認用ガード。本番ドメインでは実行させない。
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = str_starts_with($host, 'localhost') || str_starts_with($host, '127.0.0.1');

if (!$isLocal) {
    json_exit([
        'ok' => false,
        'error' => 'この手動更新APIはローカル環境専用です。本番では認証付きで実装してください。'
    ], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_exit([
        'ok' => false,
        'error' => 'POSTで実行してください。'
    ], 405);
}

if (!function_exists('exec')) {
    json_exit([
        'ok' => false,
        'error' => 'PHPのexec関数が利用できません。php.iniのdisable_functionsを確認してください。'
    ], 500);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$date = isset($input['date']) ? trim((string)$input['date']) : '';
$personId = isset($input['person_id']) ? (int)$input['person_id'] : 0;

$tz = new DateTimeZone('Asia/Tokyo');
$today = (new DateTime('now', $tz))->format('Y-m-d');
$yesterday = (new DateTime('yesterday', $tz))->format('Y-m-d');

// jobs/sync_google_health.php は、引数なし=今日、yesterday=昨日、YYYY-MM-DD=指定日 を想定。
$jobArg = '';
$targetDate = $today;

if ($date === '' || $date === $today) {
    $jobArg = '';
    $targetDate = $today;
} elseif ($date === $yesterday) {
    $jobArg = 'yesterday';
    $targetDate = $yesterday;
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $jobArg = $date;
    $targetDate = $date;
} else {
    json_exit([
        'ok' => false,
        'error' => '日付形式が不正です。YYYY-MM-DDで指定してください。'
    ], 400);
}

$root = realpath(__DIR__ . '/..');
if (!$root) {
    json_exit([
        'ok' => false,
        'error' => 'プロジェクトルートを取得できませんでした。'
    ], 500);
}

$php = PHP_BINARY;

// XAMPP環境でPHP_BINARYがうまく取れない場合の保険。
if (!$php || !is_file($php)) {
    $php = '/Applications/XAMPP/xamppfiles/bin/php';
}

if (!is_file($php)) {
    json_exit([
        'ok' => false,
        'error' => 'PHP CLIが見つかりませんでした。',
        'php_binary' => $php
    ], 500);
}

function run_job(string $root, string $php, string $relativeScript, string $arg = ''): array
{
    $scriptPath = $root . '/' . $relativeScript;

    if (!is_file($scriptPath)) {
        return [
            'ok' => false,
            'code' => 1,
            'script' => $relativeScript,
            'output' => 'script not found: ' . $scriptPath
        ];
    }

    $cmd = 'cd ' . escapeshellarg($root)
        . ' && '
        . escapeshellarg($php)
        . ' '
        . escapeshellarg($scriptPath);

    if ($arg !== '') {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    $cmd .= ' 2>&1';

    $lines = [];
    $code = 0;
    exec($cmd, $lines, $code);

    return [
        'ok' => $code === 0,
        'code' => $code,
        'script' => $relativeScript,
        'output' => implode("\n", $lines)
    ];
}

$syncResult = run_job($root, $php, 'jobs/sync_google_health.php', $jobArg);

if (!$syncResult['ok']) {
    json_exit([
        'ok' => false,
        'error' => 'Google Healthデータ同期に失敗しました。',
        'person_id' => $personId,
        'date' => $targetDate,
        'job_arg' => $jobArg,
        'sync_result' => $syncResult
    ], 500);
}

$summaryResult = run_job($root, $php, 'jobs/generate_health_ai_summary.php', $jobArg);

if (!$summaryResult['ok']) {
    json_exit([
        'ok' => false,
        'error' => 'ヘルスAIサマリ生成に失敗しました。',
        'person_id' => $personId,
        'date' => $targetDate,
        'job_arg' => $jobArg,
        'sync_result' => $syncResult,
        'summary_result' => $summaryResult
    ], 500);
}

json_exit([
    'ok' => true,
    'message' => 'Google Healthデータを更新しました。',
    'person_id' => $personId,
    'date' => $targetDate,
    'job_arg' => $jobArg,
    'sync_result' => $syncResult,
    'summary_result' => $summaryResult
]);

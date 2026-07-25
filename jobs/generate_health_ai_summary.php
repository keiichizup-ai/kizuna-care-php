<?php
// jobs/generate_health_ai_summary.php
// 会話ログ + Google Healthデータを使って、家族向けの統合見守りサマリを生成します。
// 実行例:
//   /Applications/XAMPP/xamppfiles/bin/php jobs/generate_health_ai_summary.php
//   /Applications/XAMPP/xamppfiles/bin/php jobs/generate_health_ai_summary.php yesterday
declare(strict_types=1);
require_once __DIR__ . '/../lib/google_health_client.php';
require_once __DIR__ . '/../lib/person_profile.php';
if (file_exists(__DIR__ . '/../config/openai.php')) require_once __DIR__ . '/../config/openai.php';

function kis_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function conversation_digest(PDO $pdo, int $personId, string $date): string
{
    try {
        if (!kis_table_exists($pdo, 'conversation_messages')) return '会話ログテーブルなし';
        $q = $pdo->prepare("SELECT role, content, created_at
          FROM conversation_messages
          WHERE person_id=:p AND DATE(created_at)=:d
          ORDER BY created_at ASC
          LIMIT 80");
        $q->execute([':p' => $personId, ':d' => $date]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return '会話ログなし';

        $lines = [];
        foreach ($rows as $r) {
            $role = ($r['role'] ?? '') === 'assistant' ? 'AI' : '本人';
            $txt = trim((string)($r['content'] ?? ''));
            if ($txt !== '') $lines[] = $role . ': ' . $txt;
        }
        return implode("\n", array_slice($lines, -40));
    } catch (Throwable $e) {
        return '会話ログ取得不可: ' . $e->getMessage();
    }
}

function openai_summary(string $prompt): string
{
    if (!defined('OPENAI_API_KEY') || trim((string)OPENAI_API_KEY) === '') {
        return 'OpenAI APIキー未設定のため、AIサマリを生成できませんでした。';
    }

    $model = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4.1-mini';
    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'system',
                'content' => 'あなたは高齢者見守りサービス「Kizuna Care」の家族向けサマリ担当です。診断や断定は避け、家族がやさしく様子確認できる表現にしてください。対象者を「お母さん」などと決めつけず、登録名または「利用者さん」と呼んでください。医療判断はしないでください。日本語で簡潔に。'
            ],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.35,
    ];

    $res = gh_http('POST', 'https://api.openai.com/v1/chat/completions', [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Accept: application/json'
    ], $payload);

    if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
        return 'AIサマリ生成エラー: HTTP ' . ($res['status'] ?? 'unknown');
    }

    return $res['json']['choices'][0]['message']['content'] ?? 'AIサマリを生成できませんでした。';
}

function health_line(array &$lines, string $label, $value, string $unit = ''): void
{
    if ($value === null || $value === '') return;
    $lines[] = '- ' . $label . ': ' . $value . ($unit !== '' ? ' ' . $unit : '');
}

function build_health_text(array $daily): string
{
    $lines = [];
    health_line($lines, '歩数', $daily['steps'] ?? null, '歩');
    health_line($lines, '睡眠', $daily['sleep_minutes'] ?? null, '分');
    health_line($lines, '安静時心拍', $daily['resting_heart_rate'] ?? null, 'bpm');
    health_line($lines, '平均心拍', $daily['avg_heart_rate'] ?? null, 'bpm');
    health_line($lines, '最小心拍', $daily['min_heart_rate'] ?? null, 'bpm');
    health_line($lines, '最大心拍', $daily['max_heart_rate'] ?? null, 'bpm');
    health_line($lines, 'HRV', $daily['hrv_value'] ?? null);
    health_line($lines, 'SpO2', $daily['spo2_avg'] ?? null, '%');
    health_line($lines, '呼吸数', $daily['respiratory_rate'] ?? null);
    health_line($lines, '距離', $daily['distance_meters'] ?? null, 'm');
    return $lines ? implode("\n", $lines) : '取得できたヘルスデータなし';
}

function build_exercise_text(PDO $pdo, int $personId, string $date): string
{
    $exq = $pdo->prepare('SELECT exercise_type,display_name,started_at,ended_at,distance_meters,steps,avg_heart_rate,has_gps
      FROM health_exercise_sessions
      WHERE person_id=:p AND DATE(started_at)=:d
      ORDER BY started_at ASC');
    $exq->execute([':p' => $personId, ':d' => $date]);
    $exs = $exq->fetchAll(PDO::FETCH_ASSOC);
    if (!$exs) return 'なし';

    $lines = [];
    foreach ($exs as $ex) {
        $parts = [];
        if (!empty($ex['started_at'])) $parts[] = $ex['started_at'];
        if (!empty($ex['display_name'])) $parts[] = $ex['display_name'];
        elseif (!empty($ex['exercise_type'])) $parts[] = $ex['exercise_type'];
        if ($ex['distance_meters'] !== null && $ex['distance_meters'] !== '') $parts[] = '距離' . $ex['distance_meters'] . 'm';
        if ($ex['steps'] !== null && $ex['steps'] !== '') $parts[] = '歩数' . $ex['steps'];
        if ($ex['avg_heart_rate'] !== null && $ex['avg_heart_rate'] !== '') $parts[] = '平均心拍' . $ex['avg_heart_rate'] . 'bpm';
        $parts[] = 'GPS' . ((int)$ex['has_gps'] === 1 ? 'あり' : 'なし');
        $lines[] = '- ' . implode(' ', $parts);
    }
    return implode("\n", $lines);
}

$date = $argv[1] ?? date('Y-m-d');
if ($date === 'yesterday') $date = date('Y-m-d', strtotime('-1 day'));

$pdo = gh_pdo();
kp_ensure_person_profiles_table($pdo);

$q = $pdo->prepare('SELECT * FROM health_daily_summaries WHERE summary_date=:d');
$q->execute([':d' => $date]);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "[INFO] health_daily_summaries に対象日のデータがありません date={$date}\n";
    exit;
}

foreach ($rows as $daily) {
    $personId = (int)$daily['person_id'];
    $profile = kp_get_person_profile($pdo, $personId);
    $personLabel = kp_person_label($profile);
    $relation = trim((string)($profile['relation_label'] ?? ''));

    $conv = conversation_digest($pdo, $personId, $date);
    $healthText = build_health_text($daily);
    $exText = build_exercise_text($pdo, $personId, $date);

    $prompt = "以下はKizuna Careの家族向け見守りデータです。\n\n"
        . "対象者名: {$personLabel}\n"
        . ($relation !== '' ? "続柄メモ: {$relation}\n" : '')
        . "日付: {$date}\n\n"
        . "ヘルスデータ:\n{$healthText}\n\n"
        . "運動記録:\n{$exText}\n\n"
        . "会話ログ:\n{$conv}\n\n"
        . "出力条件:\n"
        . "- 3〜5行程度\n"
        . "- 家族が見る前提\n"
        . "- 対象者名を使う。登録名がある場合は『お母さん』などの続柄で決めつけない\n"
        . "- 診断はしない\n"
        . "- データが少ない項目は断定しない\n"
        . "- 気になる点があっても不安を煽らない\n"
        . "- 最後に家族ができる自然な声かけを1つだけ提案";

    $summary = openai_summary($prompt);
    $up = $pdo->prepare('INSERT INTO health_ai_summaries
      (person_id,summary_date,summary_text,raw_prompt,model)
      VALUES (:p,:d,:s,:prompt,:m)
      ON DUPLICATE KEY UPDATE
        summary_text=VALUES(summary_text),
        raw_prompt=VALUES(raw_prompt),
        model=VALUES(model),
        created_at=NOW()');
    $up->execute([
        ':p' => $personId,
        ':d' => $date,
        ':s' => $summary,
        ':prompt' => $prompt,
        ':m' => defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4.1-mini'
    ]);
    echo "[OK] summary person_id={$personId} name={$personLabel} date={$date}\n";
}

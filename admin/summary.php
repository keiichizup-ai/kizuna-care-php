<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/openai.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$personId = (int)($_POST['person_id'] ?? 1);
$period = $_POST['period'] ?? 'day';
$baseDate = $_POST['date'] ?? date('Y-m-d');
$baseTime = strtotime($baseDate) ?: time();

if (!in_array($period, ['day', 'week', 'month'], true)) {
    $period = 'day';
}

if ($period === 'week') {
    $start = date('Y-m-d', strtotime('monday this week', $baseTime));
    $end = date('Y-m-d', strtotime('sunday this week', $baseTime));
} elseif ($period === 'month') {
    $start = date('Y-m-01', $baseTime);
    $end = date('Y-m-t', $baseTime);
} else {
    $start = date('Y-m-d', $baseTime);
    $end = $start;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT role, content, created_at FROM conversation_messages
         WHERE person_id = ?
           AND created_at >= ?
           AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([$personId, $start . ' 00:00:00', $end . ' 00:00:00']);
    $messages = $stmt->fetchAll();

    if (count($messages) === 0) {
        $summary = 'この期間の会話はありません。';
    } else {
        $lines = [];
        foreach ($messages as $message) {
            $speaker = $message['role'] === 'user' ? '本人' : 'AI';
            $lines[] = '[' . $message['created_at'] . '] ' . $speaker . ': ' . $message['content'];
        }

        $summary = call_openai_chat([
            [
                'role' => 'system',
                'content' =>
                    'あなたは家族向けの見守りサマリを作るアシスタントです。' .
                    '医療診断はしないでください。会話内容から、話題、気分の傾向、気になる変化、家族が声をかけるヒントを日本語で簡潔にまとめてください。' .
                    '構成は「主な話題」「気分の様子」「気になる点」「家族へのヒント」にしてください。',
            ],
            [
                'role' => 'user',
                'content' => implode("\n", $lines),
            ],
        ], 0.3);
    }

    $saveStmt = $pdo->prepare(
        'INSERT INTO conversation_summaries
           (person_id, period_type, period_start, period_end, summary)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           summary = VALUES(summary),
           updated_at = CURRENT_TIMESTAMP'
    );
    $saveStmt->execute([$personId, $period, $start, $end, $summary]);

    header(
        'Location: index.php?person_id=' . urlencode((string)$personId) .
        '&period=' . urlencode($period) .
        '&date=' . urlencode($baseDate)
    );
    exit;
} catch (Exception $e) {
    echo 'サマリ生成エラー: ' . h($e->getMessage());
}


<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$personId = (int)($_POST['person_id'] ?? 0);
$period = (string)($_POST['period'] ?? 'day');
$baseDate = (string)($_POST['date'] ?? date('Y-m-d'));
$scope = (string)($_POST['scope'] ?? 'period');
$baseTime = strtotime($baseDate) ?: time();

if (!in_array($period, ['day', 'week', 'month'], true)) {
    $period = 'day';
}

if (!in_array($scope, ['period', 'all'], true)) {
    $scope = 'period';
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
    $pdo->beginTransaction();

    if ($scope === 'all') {
        $stmt = $pdo->prepare('DELETE FROM conversation_summaries WHERE person_id = ?');
        $stmt->execute([$personId]);

        $stmt = $pdo->prepare('DELETE FROM conversation_messages WHERE person_id = ?');
        $stmt->execute([$personId]);
    } else {
        $stmt = $pdo->prepare(
            'DELETE FROM conversation_summaries
             WHERE person_id = ? AND period_type = ? AND period_start = ? AND period_end = ?'
        );
        $stmt->execute([$personId, $period, $start, $end]);

        $stmt = $pdo->prepare(
            'DELETE FROM conversation_messages
             WHERE person_id = ?
               AND created_at >= ?
               AND created_at < DATE_ADD(?, INTERVAL 1 DAY)'
        );
        $stmt->execute([$personId, $start . ' 00:00:00', $end . ' 00:00:00']);
    }

    $pdo->commit();

    header(
        'Location: index.php?person_id=' . urlencode((string)$personId) .
        '&period=' . urlencode($period) .
        '&date=' . urlencode($baseDate) .
        '&deleted=1'
    );
    exit;
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    echo '削除エラー: ' . h($e->getMessage());
}

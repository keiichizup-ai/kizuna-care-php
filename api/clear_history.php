<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

require_post();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    json_response(['ok' => false, 'error' => 'リクエスト形式が正しくありません。'], 400);
}

$personId = (int)($input['person_id'] ?? 0);
$mode = (string)($input['mode'] ?? 'messages');

if ($personId <= 0) {
    json_response(['ok' => false, 'error' => '会話者が指定されていません。'], 400);
}

if (!in_array($mode, ['messages', 'all'], true)) {
    json_response(['ok' => false, 'error' => '削除モードが正しくありません。'], 400);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id FROM conversation_people WHERE id = ?');
    $stmt->execute([$personId]);

    if (!$stmt->fetch()) {
        json_response(['ok' => false, 'error' => '会話者が見つかりません。'], 404);
    }

    $pdo->beginTransaction();

    $deletedSummaries = 0;

    if ($mode === 'all') {
        $summaryStmt = $pdo->prepare('DELETE FROM conversation_summaries WHERE person_id = ?');
        $summaryStmt->execute([$personId]);
        $deletedSummaries = $summaryStmt->rowCount();
    }

    $messageStmt = $pdo->prepare('DELETE FROM conversation_messages WHERE person_id = ?');
    $messageStmt->execute([$personId]);
    $deletedMessages = $messageStmt->rowCount();

    $pdo->commit();

    json_response([
        'ok' => true,
        'deleted_messages' => $deletedMessages,
        'deleted_summaries' => $deletedSummaries,
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}

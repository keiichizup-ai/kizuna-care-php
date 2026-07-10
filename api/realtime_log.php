<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

require_post();

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    json_response(['ok' => false, 'error' => 'リクエスト形式が正しくありません。'], 400);
}

$personId = (int)($input['person_id'] ?? 0);

if ($personId <= 0) {
    json_response(['ok' => false, 'error' => '会話者が指定されていません。'], 400);
}

$items = [];

if (isset($input['messages']) && is_array($input['messages'])) {
    foreach ($input['messages'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $items[] = [
            'role' => (string)($row['role'] ?? ''),
            'content' => trim((string)($row['content'] ?? '')),
        ];
    }
} else {
    $items[] = [
        'role' => (string)($input['role'] ?? ''),
        'content' => trim((string)($input['content'] ?? '')),
    ];
}

$validItems = [];
foreach ($items as $item) {
    if (!in_array($item['role'], ['user', 'assistant'], true)) {
        continue;
    }

    $content = preg_replace('/\s+/u', ' ', $item['content']);
    $content = trim((string)$content);

    if ($content === '') {
        continue;
    }

    $validItems[] = [
        'role' => $item['role'],
        'content' => $content,
    ];
}

if (count($validItems) === 0) {
    json_response([
        'ok' => true,
        'inserted_count' => 0,
        'skipped_count' => count($items),
    ]);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id FROM conversation_people WHERE id = ?');
    $stmt->execute([$personId]);
    if (!$stmt->fetch()) {
        json_response(['ok' => false, 'error' => '会話者が見つかりません。'], 404);
    }

    $pdo->beginTransaction();

    $inserted = 0;
    $skipped = 0;

    $duplicateStmt = $pdo->prepare(
        'SELECT id FROM conversation_messages
         WHERE person_id = ?
           AND role = ?
           AND content = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
         ORDER BY id DESC
         LIMIT 1'
    );

    $insertStmt = $pdo->prepare(
        'INSERT INTO conversation_messages (person_id, role, content) VALUES (?, ?, ?)'
    );

    foreach ($validItems as $item) {
        $duplicateStmt->execute([$personId, $item['role'], $item['content']]);

        if ($duplicateStmt->fetch()) {
            $skipped++;
            continue;
        }

        $insertStmt->execute([$personId, $item['role'], $item['content']]);
        $inserted++;
    }

    $invalidatedSummaries = 0;

    if ($inserted > 0) {
        // Realtime会話が保存された日のサマリは古くなるため、日・週・月の該当サマリを削除して再生成対象にします。
        $summaryStmt = $pdo->prepare(
            'DELETE FROM conversation_summaries
             WHERE person_id = ?
               AND period_start <= CURRENT_DATE
               AND period_end >= CURRENT_DATE'
        );
        $summaryStmt->execute([$personId]);
        $invalidatedSummaries = $summaryStmt->rowCount();
    }

    $pdo->commit();

    json_response([
        'ok' => true,
        'inserted_count' => $inserted,
        'skipped_count' => $skipped,
        'invalidated_summaries' => $invalidatedSummaries,
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

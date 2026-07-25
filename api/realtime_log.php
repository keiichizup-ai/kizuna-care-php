<?php
// api/realtime_log.php
// Realtime APIの会話ログを conversation_messages に保存するAPI

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';

function json_exit(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_pdo(): PDO
{
    if (function_exists('db')) {
        return db();
    }

    if (function_exists('db_conn')) {
        return db_conn();
    }

    throw new RuntimeException('DB接続関数 db() / db_conn() が見つかりません。');
}

function get_request_data(): array
{
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);

    if (is_array($json)) {
        return $json;
    }

    // 念のためフォーム送信にも対応
    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}

function normalize_role(?string $role): ?string
{
    if ($role === null) {
        return null;
    }

    $role = trim($role);

    if ($role === '') {
        return null;
    }

    $map = [
        'user' => 'user',
        'human' => 'user',
        'person' => 'user',
        '本人' => 'user',

        'assistant' => 'assistant',
        'ai' => 'assistant',
        'bot' => 'assistant',
        'kizuna' => 'assistant',
        'きずな' => 'assistant',
    ];

    return $map[$role] ?? $role;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_exit([
            'ok' => false,
            'error' => 'POSTで送信してください。'
        ], 405);
    }

    $data = get_request_data();

    $personId = isset($data['person_id']) ? (int)$data['person_id'] : 1;

    // フロント側のキー名揺れに対応
    $role = $data['role']
        ?? $data['speaker']
        ?? $data['message_role']
        ?? null;

    $content = $data['content']
        ?? $data['message']
        ?? $data['text']
        ?? '';

    $source = $data['source'] ?? 'realtime';

    $role = normalize_role(is_string($role) ? $role : null);
    $content = trim((string)$content);
    $source = trim((string)$source);

    if ($personId <= 0) {
        json_exit([
            'ok' => false,
            'error' => 'person_id が不正です。',
            'received' => $data
        ], 400);
    }

    if ($role === null) {
        json_exit([
            'ok' => false,
            'error' => 'role が必要です。',
            'received' => $data
        ], 400);
    }

    if ($content === '') {
        json_exit([
            'ok' => false,
            'error' => 'content が空です。',
            'received' => $data
        ], 400);
    }

    if ($source === '') {
        $source = 'realtime';
    }

    $pdo = get_pdo();

    $stmt = $pdo->prepare("
        INSERT INTO conversation_messages
            (person_id, role, content, source, created_at)
        VALUES
            (:person_id, :role, :content, :source, NOW())
    ");

    $stmt->execute([
        ':person_id' => $personId,
        ':role' => $role,
        ':content' => $content,
        ':source' => $source,
    ]);

    json_exit([
        'ok' => true,
        'id' => (int)$pdo->lastInsertId(),
        'person_id' => $personId,
        'role' => $role,
        'content' => $content,
        'source' => $source
    ]);

} catch (Throwable $e) {
    json_exit([
        'ok' => false,
        'error' => $e->getMessage()
    ], 500);
}

<?php
// api/dashboard_current.php

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

    throw new RuntimeException('DB接続関数 db() または db_conn() が見つかりません。');
}

function table_exists(PDO $pdo, string $tableName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ");

        $stmt->execute([
            ':table_name' => $tableName
        ]);

        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$tableName} LIKE :column_name");
        $stmt->execute([
            ':column_name' => $columnName
        ]);

        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function fetch_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_profile(PDO $pdo, int $personId): ?array
{
    if (!table_exists($pdo, 'person_profiles')) {
        return null;
    }

    return fetch_one(
        $pdo,
        "
        SELECT
            person_id,
            last_name,
            first_name,
            relation_label,
            memo,
            created_at,
            updated_at
        FROM person_profiles
        WHERE person_id = :person_id
        LIMIT 1
        ",
        [
            ':person_id' => $personId
        ]
    );
}

function make_display_name(?array $profile, int $personId): string
{
    if (!$profile) {
        return '利用者';
    }

    $lastName = trim((string)($profile['last_name'] ?? ''));
    $firstName = trim((string)($profile['first_name'] ?? ''));

    $name = trim($lastName . ' ' . $firstName);

    if ($name !== '') {
        return $name;
    }

    return '利用者';
}

function get_connection(PDO $pdo, int $personId): ?array
{
    if (!table_exists($pdo, 'health_connections')) {
        return null;
    }

    return fetch_one(
        $pdo,
        "
        SELECT
            status,
            last_synced_at,
            last_error
        FROM health_connections
        WHERE person_id = :person_id
        ORDER BY id DESC
        LIMIT 1
        ",
        [
            ':person_id' => $personId
        ]
    );
}

function get_daily(PDO $pdo, int $personId, string $date): ?array
{
    if (!table_exists($pdo, 'health_daily_summaries')) {
        return null;
    }

    return fetch_one(
        $pdo,
        "
        SELECT *
        FROM health_daily_summaries
        WHERE person_id = :person_id
          AND summary_date = :summary_date
        LIMIT 1
        ",
        [
            ':person_id' => $personId,
            ':summary_date' => $date
        ]
    );
}

function get_ai_summary(PDO $pdo, int $personId, string $date): ?array
{
    if (!table_exists($pdo, 'health_ai_summaries')) {
        return null;
    }

    return fetch_one(
        $pdo,
        "
        SELECT *
        FROM health_ai_summaries
        WHERE person_id = :person_id
          AND summary_date = :summary_date
        ORDER BY id DESC
        LIMIT 1
        ",
        [
            ':person_id' => $personId,
            ':summary_date' => $date
        ]
    );
}

function get_exercises(PDO $pdo, int $personId, string $date): array
{
    if (!table_exists($pdo, 'health_exercise_sessions')) {
        return [];
    }

    $start = $date . ' 00:00:00';
    $end = $date . ' 23:59:59';

    return fetch_all(
        $pdo,
        "
        SELECT
            id,
            external_id,
            exercise_type,
            started_at,
            ended_at,
            duration_seconds,
            steps,
            distance_meters,
            avg_heart_rate,
            has_gps,
            created_at
        FROM health_exercise_sessions
        WHERE person_id = :person_id
          AND started_at BETWEEN :start_at AND :end_at
        ORDER BY started_at DESC
        ",
        [
            ':person_id' => $personId,
            ':start_at' => $start,
            ':end_at' => $end
        ]
    );
}

function make_status(?array $daily, ?array $connection): array
{
    if (!$connection || ($connection['status'] ?? '') !== 'active') {
        return [
            'level' => 'gray',
            'label' => '未連携',
            'note' => 'Google Health / Fitbit連携がまだ完了していません。'
        ];
    }

    if (!$daily) {
        return [
            'level' => 'gray',
            'label' => 'データ待ち',
            'note' => 'ヘルスデータはまだ取得されていません。同期後に表示されます。'
        ];
    }

    $steps = isset($daily['steps']) ? (int)$daily['steps'] : null;
    $avgHeartRate = isset($daily['avg_heart_rate']) ? (int)$daily['avg_heart_rate'] : null;

    if ($avgHeartRate !== null && ($avgHeartRate >= 110 || $avgHeartRate <= 45)) {
        return [
            'level' => 'red',
            'label' => '確認推奨',
            'note' => '心拍データに普段と違う可能性があります。体調をやさしく確認してください。'
        ];
    }

    if ($steps !== null && $steps < 1000) {
        return [
            'level' => 'yellow',
            'label' => '活動少なめ',
            'note' => '歩数は少なめです。体調や外出予定を軽く確認すると良さそうです。'
        ];
    }

    return [
        'level' => 'green',
        'label' => 'おおむね安定',
        'note' => '今日のデータ上、大きく気になる変化は見られません。'
    ];
}

function normalize_role(string $role): string
{
    $role = strtolower(trim($role));

    if (in_array($role, ['assistant', 'ai', 'bot', 'kizuna'], true)) {
        return 'assistant';
    }

    if (in_array($role, ['user', 'human', 'person', '本人'], true)) {
        return 'user';
    }

    return $role ?: 'user';
}

function role_label(string $role): string
{
    $role = normalize_role($role);

    if ($role === 'assistant') {
        return 'AI';
    }

    return '本人';
}

function load_conversation(PDO $pdo, int $personId, string $date): array
{
    $start = $date . ' 00:00:00';
    $end = $date . ' 23:59:59';

    try {
        /*
         * source カラムがない既存テーブルでも動くように、
         * source はSELECTしません。
         */
        $countStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total_count,
                SUM(CASE WHEN role IN ('user', 'human', 'person', '本人') THEN 1 ELSE 0 END) AS user_count,
                SUM(CASE WHEN role IN ('assistant', 'ai', 'bot', 'kizuna') THEN 1 ELSE 0 END) AS assistant_count
            FROM conversation_messages
            WHERE person_id = :person_id
              AND created_at BETWEEN :start_at AND :end_at
        ");

        $countStmt->execute([
            ':person_id' => $personId,
            ':start_at' => $start,
            ':end_at' => $end,
        ]);

        $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare("
            SELECT
                id,
                person_id,
                role,
                content,
                created_at
            FROM conversation_messages
            WHERE person_id = :person_id
              AND created_at BETWEEN :start_at AND :end_at
            ORDER BY created_at DESC
            LIMIT 20
        ");

        $stmt->execute([
            ':person_id' => $personId,
            ':start_at' => $start,
            ':end_at' => $end,
        ]);

        $recentDesc = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $recentMessages = array_reverse($recentDesc);

        $highlights = make_conversation_highlights($recentMessages);

        return [
            'table_exists' => true,
            'count' => (int)($counts['total_count'] ?? 0),
            'user_count' => (int)($counts['user_count'] ?? 0),
            'assistant_count' => (int)($counts['assistant_count'] ?? 0),
            'recent_messages' => $recentMessages,
            'highlights' => $highlights,
        ];

    } catch (Throwable $e) {
        return [
            'table_exists' => false,
            'count' => 0,
            'user_count' => 0,
            'assistant_count' => 0,
            'recent_messages' => [],
            'highlights' => [
                '会話ログを取得できませんでした：' . $e->getMessage()
            ],
        ];
    }
}

function make_conversation_highlights(array $messages): array
{
    if (empty($messages)) {
        return [
            '今日の会話ログはまだありません。'
        ];
    }

    $highlights = [];

    foreach ($messages as $msg) {
        $content = trim((string)($msg['content'] ?? ''));

        if ($content === '') {
            continue;
        }

        $role = role_label((string)($msg['role'] ?? 'user'));

        $content = preg_replace('/\s+/u', ' ', $content);
        $short = mb_substr($content, 0, 80);

        if (mb_strlen($content) > 80) {
            $short .= '…';
        }

        $highlights[] = $role . '：' . $short;

        if (count($highlights) >= 3) {
            break;
        }
    }

    if (empty($highlights)) {
        $highlights[] = '今日の会話ログはまだありません。';
    }

    return $highlights;
}

try {
    $pdo = get_pdo();

    $personId = isset($_GET['person_id']) ? (int)$_GET['person_id'] : 0;

    if ($personId <= 0) {
        json_exit([
            'ok' => false,
            'error' => 'person_id が必要です。'
        ], 400);
    }

    $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
        ? $_GET['date']
        : (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

    $profile = get_profile($pdo, $personId);
    $displayName = make_display_name($profile, $personId);
    $personLabel = $displayName . 'さん';

    $connection = get_connection($pdo, $personId);
    $daily = get_daily($pdo, $personId, $date);
    $aiSummary = get_ai_summary($pdo, $personId, $date);
    $conversation = load_conversation($pdo, $personId, $date);
    $exercises = get_exercises($pdo, $personId, $date);
    $status = make_status($daily, $connection);

    json_exit([
        'ok' => true,
        'date' => $date,
        'person_id' => $personId,
        'profile' => $profile,
        'display_name' => $displayName,
        'person_label' => $personLabel,
        'status' => $status,
        'connection' => $connection,
        'daily' => $daily,
        'ai_summary' => $aiSummary,
        'conversation' => $conversation,
        'exercises' => $exercises,
    ]);

} catch (Throwable $e) {
    json_exit([
        'ok' => false,
        'error' => 'ダッシュボードデータの取得に失敗しました。',
        'detail' => $e->getMessage()
    ], 500);
}
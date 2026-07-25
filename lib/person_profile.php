<?php
// lib/person_profile.php
// Kizuna Careの「利用者名」管理用の小さな共通ライブラリです。
// 既存の person_id に、姓・名・続柄メモを後付けで紐づけます。
declare(strict_types=1);

if (!function_exists('gh_pdo')) {
    if (file_exists(__DIR__ . '/google_health_client.php')) {
        require_once __DIR__ . '/google_health_client.php';
    }
}

function kp_pdo(): PDO
{
    if (function_exists('gh_pdo')) {
        return gh_pdo();
    }
    if (file_exists(__DIR__ . '/../config/db.php')) require_once __DIR__ . '/../config/db.php';
    if (file_exists(__DIR__ . '/../funcs.php')) require_once __DIR__ . '/../funcs.php';
    if (function_exists('db_conn')) return db_conn();
    if (function_exists('db')) return db();
    throw new RuntimeException('DB接続関数 db_conn() または db() が見つかりません。');
}

function kp_ensure_person_profiles_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS person_profiles (
      person_id INT NOT NULL PRIMARY KEY,
      last_name VARCHAR(80) NULL,
      first_name VARCHAR(80) NULL,
      relation_label VARCHAR(80) NULL,
      memo TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function kp_get_person_profile(PDO $pdo, int $personId): array
{
    kp_ensure_person_profiles_table($pdo);
    $stmt = $pdo->prepare('SELECT person_id,last_name,first_name,relation_label,memo,created_at,updated_at FROM person_profiles WHERE person_id=:p LIMIT 1');
    $stmt->execute([':p' => $personId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    return [
        'person_id' => $personId,
        'last_name' => '',
        'first_name' => '',
        'relation_label' => '',
        'memo' => '',
        'created_at' => null,
        'updated_at' => null,
    ];
}

function kp_save_person_profile(PDO $pdo, int $personId, string $lastName, string $firstName, string $relationLabel = '', string $memo = ''): array
{
    kp_ensure_person_profiles_table($pdo);

    $lastName = trim(mb_substr($lastName, 0, 80));
    $firstName = trim(mb_substr($firstName, 0, 80));
    $relationLabel = trim(mb_substr($relationLabel, 0, 80));
    $memo = trim(mb_substr($memo, 0, 1000));

    $stmt = $pdo->prepare("INSERT INTO person_profiles
      (person_id,last_name,first_name,relation_label,memo)
      VALUES (:p,:last_name,:first_name,:relation_label,:memo)
      ON DUPLICATE KEY UPDATE
        last_name=VALUES(last_name),
        first_name=VALUES(first_name),
        relation_label=VALUES(relation_label),
        memo=VALUES(memo),
        updated_at=NOW()");
    $stmt->execute([
        ':p' => $personId,
        ':last_name' => $lastName !== '' ? $lastName : null,
        ':first_name' => $firstName !== '' ? $firstName : null,
        ':relation_label' => $relationLabel !== '' ? $relationLabel : null,
        ':memo' => $memo !== '' ? $memo : null,
    ]);

    return kp_get_person_profile($pdo, $personId);
}

function kp_person_full_name(array $profile, string $fallback = '利用者'): string
{
    $last = trim((string)($profile['last_name'] ?? ''));
    $first = trim((string)($profile['first_name'] ?? ''));
    $name = trim($last . ' ' . $first);
    return $name !== '' ? $name : $fallback;
}

function kp_person_label(array $profile, string $fallback = '利用者さん'): string
{
    $name = kp_person_full_name($profile, '');
    if ($name === '') return $fallback;
    return $name . 'さん';
}

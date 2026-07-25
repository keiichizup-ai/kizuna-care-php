<?php
// api/google_health_callback.php
// Google Health OAuth callback。連携後は統合ダッシュボードへ戻します。
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../lib/google_health_client.php';

function gh_redirect_after_connect(int $personId, array $params = []): void {
    // 統合UIをメインにするため、デフォルトは family/dashboard.php。
    // config/google_health.php で GOOGLE_HEALTH_AFTER_CONNECT_URL を定義すれば上書きできます。
    $url = defined('GOOGLE_HEALTH_AFTER_CONNECT_URL') ? trim((string)GOOGLE_HEALTH_AFTER_CONNECT_URL) : '../family/dashboard.php';
    if ($url === '') $url = '../family/dashboard.php';

    // /family/... の絶対パス指定が入っていても、ローカルのサブディレクトリ配置に補正する。
    if (str_starts_with($url, '/family/')) {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $projectBase = dirname(dirname($scriptName));
        if ($projectBase === '/' || $projectBase === '\\' || $projectBase === '.') $projectBase = '';
        $url = rtrim($projectBase, '/') . $url;
    }

    $query = array_merge(['person_id' => $personId], $params);
    $separator = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $separator . http_build_query($query));
    exit;
}

try {
    if (!isset($_GET['code'], $_GET['state'])) throw new RuntimeException('code/state がありません');
    if (!hash_equals($_SESSION['google_health_oauth_state'] ?? '', (string)$_GET['state'])) throw new RuntimeException('state が一致しません');
    $personId = (int)($_SESSION['google_health_person_id'] ?? 0);
    if ($personId <= 0) throw new RuntimeException('person_id がありません');

    $pdo = gh_pdo();
    $token = gh_exchange_code((string)$_GET['code']);
    $access = $token['access_token'] ?? null;
    if (!$access) throw new RuntimeException('access_tokenを取得できませんでした');
    $identity = gh_identity($access);

    $existingStmt = $pdo->prepare("SELECT * FROM health_connections WHERE person_id=:p AND provider='google_health'");
    $existingStmt->execute([':p' => $personId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    $refreshEnc = !empty($token['refresh_token']) ? gh_encrypt($token['refresh_token']) : ($existing['refresh_token_enc'] ?? null);
    $expiresAt = date('Y-m-d H:i:s', time() + (int)($token['expires_in'] ?? 3500) - 60);

    $stmt = $pdo->prepare("INSERT INTO health_connections
      (person_id, provider, google_health_user_id, fitbit_legacy_user_id, access_token_enc, refresh_token_enc, token_expires_at, scopes, status)
      VALUES (:p, 'google_health', :ghid, :fid, :a, :r, :e, :s, 'active')
      ON DUPLICATE KEY UPDATE
        google_health_user_id=VALUES(google_health_user_id),
        fitbit_legacy_user_id=VALUES(fitbit_legacy_user_id),
        access_token_enc=VALUES(access_token_enc),
        refresh_token_enc=VALUES(refresh_token_enc),
        token_expires_at=VALUES(token_expires_at),
        scopes=VALUES(scopes),
        status='active',
        last_error=NULL,
        updated_at=NOW()");
    $stmt->execute([
        ':p' => $personId,
        ':ghid' => $identity['healthUserId'] ?? null,
        ':fid' => $identity['legacyUserId'] ?? null,
        ':a' => gh_encrypt($access),
        ':r' => $refreshEnc,
        ':e' => $expiresAt,
        ':s' => $token['scope'] ?? implode(' ', GOOGLE_HEALTH_SCOPES),
    ]);

    unset($_SESSION['google_health_oauth_state'], $_SESSION['google_health_person_id']);
    gh_redirect_after_connect($personId, ['connected' => 1]);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Google Health連携エラー</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
}

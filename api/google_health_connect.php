<?php
// api/google_health_connect.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/google_health.php';

// TODO: 既存の家族ログインチェックを入れる
$personId = (int)($_GET['person_id'] ?? 0);
if ($personId <= 0) { http_response_code(400); echo 'person_id が必要です'; exit; }

$state = bin2hex(random_bytes(16));
$_SESSION['google_health_oauth_state'] = $state;
$_SESSION['google_health_person_id'] = $personId;

$params = [
    'client_id' => GOOGLE_HEALTH_CLIENT_ID,
    'redirect_uri' => GOOGLE_HEALTH_REDIRECT_URI,
    'response_type' => 'code',
    'access_type' => 'offline',
    'include_granted_scopes' => 'true',
    'prompt' => 'consent',
    'scope' => implode(' ', GOOGLE_HEALTH_SCOPES),
    'state' => $state,
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

if (isset($_GET['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "AUTH URL:\n";
    echo $authUrl . "\n\n";
    echo "CLIENT ID:\n";
    echo GOOGLE_HEALTH_CLIENT_ID . "\n\n";
    echo "REDIRECT URI:\n";
    echo GOOGLE_HEALTH_REDIRECT_URI . "\n\n";
    echo "SCOPES:\n";
    echo implode("\n", GOOGLE_HEALTH_SCOPES) . "\n";
    exit;
}

header('Location: ' . $authUrl);
exit;

header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
exit;

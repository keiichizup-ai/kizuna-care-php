<?php
// config/google_health.example.php
// このファイルを config/google_health.php にコピーして、Google Health APIの設定を入れてください。

define('GOOGLE_HEALTH_CLIENT_ID', 'xxxxxxxx.apps.googleusercontent.com');
define('GOOGLE_HEALTH_CLIENT_SECRET', 'xxxxxxxx');

define(
    'GOOGLE_HEALTH_REDIRECT_URI',
    'https://example.com/kizuna-care-php/api/google_health_callback.php'
);

define('GOOGLE_HEALTH_AFTER_CONNECT_URL', '../family/dashboard.php');

// トークン暗号化用。十分に長いランダム文字列にしてください。
define('GOOGLE_HEALTH_TOKEN_KEY', 'replace_with_long_random_secret_key');

// 本番でGoogle Healthデータ更新ボタンを使う場合の簡易パスコード
define('GOOGLE_HEALTH_MANUAL_SYNC_PASSWORD', 'replace_with_manual_sync_password');

// さくら等でPHP実行パスを指定する場合
define('HEALTH_SYNC_PHP_BIN', '/usr/local/bin/php');

define('GOOGLE_HEALTH_SCOPES', [
    'https://www.googleapis.com/auth/googlehealth.activity_and_fitness.readonly',
    'https://www.googleapis.com/auth/googlehealth.sleep.readonly',
    'https://www.googleapis.com/auth/googlehealth.health_metrics_and_measurements.readonly',
    'https://www.googleapis.com/auth/googlehealth.location.readonly',
]);
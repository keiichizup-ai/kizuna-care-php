<?php
// config/config.php にコピーして、自分の環境に合わせて変更してください。

define('DB_HOST', 'localhost');
define('DB_NAME', 'kizuna_care_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// OpenAI APIキー。公開リポジトリには絶対に載せないでください。
define('OPENAI_API_KEY', 'YOUR_OPENAI_API_KEY_HERE');
define('OPENAI_MODEL', 'gpt-4.1-mini');

// 家族用管理画面の簡易ログイン設定。
// 本格運用ではFirebase Authや独自ユーザーDBに置き換える想定です。
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'hkjhkjhkjogh');


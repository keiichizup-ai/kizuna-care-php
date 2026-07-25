# Kizuna-AI Google Health / Fitbit Add-on

Fitbit Charge 6 / Google Health API 連携用の追加コードです。

## できること

- Google OAuth 2.0でGoogle Health API連携
- Fitbit / Google Healthから日次データ取得
  - 歩数
  - 睡眠
  - 心拍
  - 安静時心拍
  - HRV
  - SpO2
  - 呼吸数
- 家族画面にヘルスカード表示
- 会話ログ + 健康データのAIサマリ
- 運動として記録されたGPSルート表示

## 重要な制約

Fitbit Charge 6のGPSは「運動時のルート」向けです。
この追加コードは、常時現在地・自宅外通知・徘徊検知をCharge 6単体で実現するものではありません。
位置見守りは別途、スマホアプリまたはGPS/LTE端末で実装してください。

## 追加ファイル

```text
sql/google_health_addon.sql
config/google_health.example.php
lib/google_health_client.php
api/google_health_connect.php
api/google_health_callback.php
api/health_current.php
api/exercise_route.php
family/health.php
jobs/sync_google_health.php
jobs/generate_health_ai_summary.php
```

## セットアップ

### 1. DB追加

```bash
mysql -u USER -p DB_NAME < sql/google_health_addon.sql
```

### 2. 設定ファイル作成

```bash
cp config/google_health.example.php config/google_health.php
```

`config/google_health.php` に以下を設定します。

```php
define('GOOGLE_HEALTH_CLIENT_ID', '...');
define('GOOGLE_HEALTH_CLIENT_SECRET', '...');
define('GOOGLE_HEALTH_REDIRECT_URI', 'https://YOUR_DOMAIN/api/google_health_callback.php');
define('GOOGLE_HEALTH_TOKEN_KEY', '...');
```

`config/google_health.php` はGitHubに上げないでください。

### 3. .gitignore追加

```text
config/google_health.php
```

### 4. Google Cloud設定

- Google Health APIを有効化
- OAuth 2.0 クライアントを作成
- Authorized redirect URIs に `GOOGLE_HEALTH_REDIRECT_URI` を登録
- OAuth同意画面に必要スコープを追加
- テスト中はテストユーザーのメールアドレスを追加

### 5. 連携画面

```text
/family/health.php?person_id=1
```

「Google Healthと連携」を押して許可します。

### 6. 同期ジョブ

手動テスト:

```bash
php jobs/sync_google_health.php
php jobs/sync_google_health.php yesterday
```

cron例:

```cron
0 */3 * * * /usr/local/bin/php /home/YOUR_ACCOUNT/www/kizuna/jobs/sync_google_health.php
30 7 * * * /usr/local/bin/php /home/YOUR_ACCOUNT/www/kizuna/jobs/sync_google_health.php yesterday
40 7 * * * /usr/local/bin/php /home/YOUR_ACCOUNT/www/kizuna/jobs/generate_health_ai_summary.php yesterday
```

## 既存プロジェクトに合わせて修正する場所

### DB接続

`lib/google_health_client.php` の `gh_pdo()` を確認してください。
既存の `db_conn()` または `db()` を使う想定です。

### ログインチェック

以下のファイルに既存の認証チェックを入れてください。

```text
api/google_health_connect.php
api/google_health_callback.php
api/health_current.php
api/exercise_route.php
family/health.php
```

### 会話ログテーブル

`jobs/generate_health_ai_summary.php` の `conversation_digest()` は、
`conversation_messages(person_id, role, content, created_at)` を想定しています。
既存DBのテーブル名・カラム名に合わせて修正してください。

## データ取得の安定性

Google Health APIのレスポンス構造はデータ型ごとに違います。
このコードはMVP用として可能な範囲で抽出し、`health_daily_summaries.raw_json` に元レスポンスも保存します。
値がNULLになる場合は `raw_json` を見て抽出関数を調整してください。

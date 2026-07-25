# Kizuna Care 統合ダッシュボード追加パッチ

## 追加・更新ファイル

- `family/dashboard.php` 新規
  - 会話サマリ + ヘルス見守りを1画面に統合した家族向けダッシュボードです。
  - 利用者名の登録UIも含みます。

- `api/dashboard_current.php` 新規
  - ダッシュボード表示用の統合JSON APIです。
  - `health_daily_summaries`、`health_ai_summaries`、`health_exercise_sessions`、`health_connections`、`conversation_messages` を参照します。

- `api/person_profile.php` 新規
  - 利用者の姓・名・続柄メモを保存/取得するAPIです。

- `lib/person_profile.php` 新規
  - 利用者名管理の共通関数です。

- `jobs/generate_health_ai_summary.php` 上書き
  - サマリ生成時に、登録名を使います。
  - 「お母さん」などの続柄を決めつけないようにプロンプトを修正しています。

- `api/google_health_callback.php` 上書き
  - OAuth連携後の戻り先を `family/dashboard.php` に変更しています。

- `sql/dashboard_addon.sql` 新規
  - `person_profiles` テーブルを追加します。

## 反映手順

1. ZIP内のファイルを既存プロジェクトへコピーします。

2. DBにテーブルを追加します。

```bash
mysql -u USER -p DB_NAME < sql/dashboard_addon.sql
```

またはphpMyAdminで `sql/dashboard_addon.sql` の内容を実行してください。

3. ブラウザで開きます。

```text
http://localhost/kizuna-care-php/family/dashboard.php?person_id=1
```

4. 「設定」タブで姓・名を登録します。

5. ヘルスデータとAIサマリを更新します。

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/kizuna-care-php
/Applications/XAMPP/xamppfiles/bin/php jobs/sync_google_health.php
/Applications/XAMPP/xamppfiles/bin/php jobs/generate_health_ai_summary.php
```

## 注意

- 各PHPファイル内の `TODO: 既存の家族ログインチェック` は、本番前に既存認証に合わせて追加してください。
- `conversation_messages` のテーブル名・カラム名が違う場合は、`api/dashboard_current.php` と `jobs/generate_health_ai_summary.php` の会話ログ取得部分を既存DBに合わせて修正してください。
- `family/dashboard.php` はローカルのサブディレクトリ配置に合わせて `../api/...` を使っています。

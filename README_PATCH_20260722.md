# Google Health連携 修正版ファイル

このZIPは、Kizuna-AI / Kizuna Care のGoogle Health連携アドオンに対する修正版です。

## 反映内容

1. `family/health.php`
   - `../api/health_current.php` / `../api/exercise_route.php` を使うように修正
   - `Google Healthと連携` ボタンも `../api/google_health_connect.php` に修正
   - API失敗時に「読み込み中」のまま止まらず、画面にエラー表示するように修正
   - 距離・最小心拍・最大心拍・SpO2表示カードを追加

2. `lib/google_health_client.php`
   - `gh_extract_distance_meters()` を追加
   - Google Healthの `distance.millimetersSum` をメートルへ正しく変換

3. `jobs/sync_google_health.php`
   - 距離取得を `gh_num()` から `gh_extract_distance_meters()` に変更
   - `year: 2026` を距離として誤取得する問題を修正

4. `jobs/generate_health_ai_summary.php`
   - `null` 項目をAIプロンプトに出さないように修正
   - データが少ない場合に断定しない条件を追加

5. `api/google_health_callback.php`
   - OAuth後の戻り先が `http://localhost/family/...` になってしまう問題を補正
   - `/kizuna-care-php/family/...` のようなサブディレクトリ配置にも対応

## 反映方法

既存プロジェクトの同名ファイルへ上書きしてください。

```text
api/google_health_callback.php
family/health.php
jobs/generate_health_ai_summary.php
jobs/sync_google_health.php
lib/google_health_client.php
```

その後、手動で再同期します。

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/kizuna-care-php
/Applications/XAMPP/xamppfiles/bin/php jobs/sync_google_health.php
/Applications/XAMPP/xamppfiles/bin/php jobs/generate_health_ai_summary.php
```

確認URL：

```text
http://localhost/kizuna-care-php/api/health_current.php?person_id=1
http://localhost/kizuna-care-php/family/health.php?person_id=1
```

`distance_meters` が `2026` のような年の値ではなく、実際の距離に近い値になっていればOKです。

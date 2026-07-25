# Google Healthデータ手動更新ボタン 追加パッチ

## 追加・更新ファイル

- `api/health_sync_now.php` 新規
- `family/dashboard.php` 更新

## 使い方

1. このZIPの中身を既存の `kizuna-care-php` に上書きコピーします。
2. ローカルでダッシュボードを開きます。

```text
http://localhost/kizuna-care-php/family/dashboard.php?person_id=1
```

特定日を見る場合：

```text
http://localhost/kizuna-care-php/family/dashboard.php?person_id=1&date=2026-07-22
```

3. 画面右上の「Google Healthデータを更新」を押します。

## 注意

このボタンは Fitbit Charge 6 本体を直接同期するものではありません。
先にスマホの Fitbit アプリで Charge 6 の同期を済ませてから押してください。

流れ：

```text
Fitbit Charge 6
↓
スマホのFitbitアプリで同期
↓
Google Health API側にデータ反映
↓
Kizunaダッシュボードで「Google Healthデータを更新」
↓
Kizuna側DBとAIサマリ更新
```

## 本番利用について

`api/health_sync_now.php` はローカル専用のガードを入れています。
本番で使う場合は以下を入れてください。

- ログインチェック
- 家族アカウントの閲覧権限チェック
- CSRF対策
- 連打防止
- 更新回数制限


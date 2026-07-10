# Kizuna Care PHP Realtime ログ保存連動版

既存の `kizuna-care-php` に、Realtime版の会話ログ保存を追加する版です。
Realtime版で取得できた本人発話・きずなちゃん返答の文字起こしを、通常版と同じ `conversation_messages` テーブルへ保存します。

## 追加・改善点

- `realtime.php` にログ保存ステータスを追加
- `assets/realtime.js` でRealtimeイベントから本人発話・AI返答の文字起こしを保存
- `api/realtime_log.php` を強化
  - 通常版と同じ `conversation_messages` に保存
  - 直近2分以内の重複保存を防止
  - 保存後、該当日の既存サマリを無効化して再生成対象にする
- `api/realtime_token.php` を安定版へ更新
  - OpenAI APIキーの形式判定を調整
  - `speed` 指定を外して小数桁エラーを回避
  - IPv4優先とタイムアウト指定を追加
- 家族用管理画面のリンクと説明を更新

## 使い方

既存プロジェクトへ以下を上書き・追加してください。

```text
realtime.php
assets/realtime.js
assets/style.css
api/realtime_token.php
api/realtime_log.php
admin/index.php
README.md
```

通常版:

```text
http://localhost/kizuna-care-php/
```

Realtime版:

```text
http://localhost/kizuna-care-php/realtime.php
```

家族用管理画面:

```text
http://localhost/kizuna-care-php/admin/index.php
```

## 管理画面・サマリとの連動

Realtime版で保存されたログは、通常版と同じ `conversation_messages` に入ります。
そのため、家族用管理画面の会話ログ一覧にも表示され、既存の `admin/summary.php` のサマリ生成対象にも含まれます。

Realtimeログ保存後は、該当日の既存サマリを削除します。
家族用画面で「サマリ生成」を押すと、Realtime版の会話を含めて最新サマリが作られます。

## 注意

- Realtime APIの音声出力そのものは保存しません。保存するのはRealtimeイベントから取得できた文字起こしです。
- ユーザー側の文字起こしが失敗した場合、その発話は保存されません。
- `api/realtime_token.php` で `ok: true` が出ることを確認してから、Realtime画面を試してください。
- `ek_...` で始まる短命トークンは画面共有やスクショで見せないでください。

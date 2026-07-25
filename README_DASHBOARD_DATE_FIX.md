# Kizuna Care Dashboard 日付ズレ修正パッチ

## 内容

`family/dashboard.php` の「今日」「昨日」「前日」ボタンの日付計算を、日本時間（Asia/Tokyo）基準に修正しました。

これにより、日本時間の深夜帯に「今日」を押すと前日になる問題を防ぎます。

## 反映方法

このZIP内の以下ファイルを既存プロジェクトへ上書きしてください。

```text
family/dashboard.php
```

## 修正ポイント

- `date('Y-m-d')` の環境依存を避ける
- `DateTimeZone('Asia/Tokyo')` を使う
- 今日：日本時間の今日
- 昨日：日本時間の昨日
- 前日：表示中の日付から1日前

## 確認URL

```text
http://localhost/kizuna-care-php/family/dashboard.php?person_id=1
```

今日ボタンが日本時間の今日、昨日ボタンが日本時間の昨日になればOKです。

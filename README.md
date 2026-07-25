# Kizuna Care PHP

高齢者・家族向けの会話見守り・ヘルス見守りアプリです。

## 主な機能

- Realtime APIによる音声会話
- 会話ログ保存
- 家族向け統合見守りダッシュボード
- Google Health API連携
- 歩数・心拍・距離などのヘルスデータ表示
- AI見守りサマリ生成

## 必要環境

- PHP 8.2以上
- MySQL
- PDO
- OpenAI APIキー
- Google Health API OAuth設定

## セットアップ

`config/*.example.php` をコピーして、環境に合わせて設定します。

```bash
cp config/config.example.php config/config.php
cp config/openai.example.php config/openai.php
cp config/google_health.example.php config/google_health.php
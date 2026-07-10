<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';

$people = db()->query('SELECT id, display_name FROM conversation_people ORDER BY id ASC')->fetchAll();
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kizuna Care PHP</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="app">
    <section class="conversation">
      <div class="topbar">
        <div>
          <h1>きずなちゃん</h1>
          <p>会話をはじめると、話し終わりに自動で返事をします。</p>
        </div>
        <div class="header-links">
          <a href="realtime.php">Realtime検証版</a>
          <a href="admin/login.php">家族用画面</a>
        </div>
      </div>

      <label class="field">
        会話する人
        <select id="personId">
          <?php foreach ($people as $person): ?>
            <option value="<?= h($person['id']) ?>"><?= h($person['display_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <!--
        2Dパーツ分けアバター
        PHPではなくブラウザ側のHTML/CSS/JSで動かします。
        後でLive2Dへ移行する場合も、この #avatar の状態切替ロジックを流用できます。
      -->
      <div class="avatar" id="avatar" data-status="idle" data-mouth="closed" aria-live="polite">
        <div class="avatar-stage">
          <div class="avatar-aura"></div>
          <div class="avatar-shadow"></div>

          <div class="avatar-character" aria-label="きずなちゃんアバター">
            <div class="avatar-hair hair-back"></div>
            <div class="avatar-neck"></div>
            <div class="avatar-body"></div>
            <div class="avatar-head">
              <div class="avatar-hair hair-front"></div>
              <div class="avatar-bangs bang-left"></div>
              <div class="avatar-bangs bang-center"></div>
              <div class="avatar-bangs bang-right"></div>

              <div class="avatar-eyebrow eyebrow-left"></div>
              <div class="avatar-eyebrow eyebrow-right"></div>
              <div class="avatar-eye eye-left">
                <span class="pupil"></span>
              </div>
              <div class="avatar-eye eye-right">
                <span class="pupil"></span>
              </div>
              <div class="avatar-cheek cheek-left"></div>
              <div class="avatar-cheek cheek-right"></div>
              <div class="avatar-mouth" id="avatarMouth"></div>
            </div>
          </div>
        </div>

        <div class="status-card">
          <div class="status" id="statusText">待機中</div>
          <div class="status-sub" id="statusSubText">「会話をはじめる」を押してください</div>
          <div class="voice-dots" aria-hidden="true">
            <span></span><span></span><span></span>
          </div>
        </div>
      </div>

      <div class="controls">
        <button id="startBtn" type="button">会話をはじめる</button>
        <button id="stopBtn" type="button" class="secondary" disabled>会話を終わる</button>
      </div>

      <form id="textForm" class="text-form">
        <input id="textInput" type="text" placeholder="文字入力でも会話できます">
        <button type="submit">送信</button>
      </form>

      <details class="maintenance-panel">
        <summary>データ操作</summary>
        <p class="muted">選択中の会話者のデータを削除します。削除後は元に戻せません。</p>
        <div class="maintenance-actions">
          <button id="clearHistoryBtn" type="button" class="danger small">会話履歴を削除</button>
          <button id="clearAllDataBtn" type="button" class="danger small">会話履歴・サマリを削除</button>
        </div>
      </details>

      <div class="log" id="log" aria-live="polite"></div>
    </section>
  </main>
  <script src="assets/app.js"></script>
</body>
</html>

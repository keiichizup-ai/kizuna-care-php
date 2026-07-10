<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/helpers.php';

try {
    $people = db()
        ->query('SELECT id, display_name FROM conversation_people ORDER BY id ASC')
        ->fetchAll();
} catch (Throwable $e) {
    $people = [];
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>きずなちゃん Realtime</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
  <main class="app">
    <section class="conversation realtime-conversation">
      <div class="topbar">
        <div>
          <h1>きずなちゃん Realtime</h1>
          <p>低遅延の音声会話を試すための検証画面です。</p>
        </div>

        <div class="header-links">
          <a href="index.php">通常会話へ戻る</a>
          <a href="admin/index.php">家族用画面</a>
        </div>
      </div>

      <div class="realtime-notice">
        <strong>Realtimeログ保存対応版です。</strong>
        <p>
          この画面での本人発話・きずなちゃん返答は、取得できた文字起こしを
          通常版と同じ会話ログへ保存します。家族用画面の会話ログ・サマリ生成にも反映されます。
        </p>
      </div>

      <label class="field">
        会話する人
        <select id="personId">
          <?php if (count($people) > 0): ?>
            <?php foreach ($people as $person): ?>
              <option value="<?= h((string)$person['id']) ?>">
                <?= h((string)$person['display_name']) ?>
              </option>
            <?php endforeach; ?>
          <?php else: ?>
            <option value="1">ゲスト</option>
          <?php endif; ?>
        </select>
      </label>

      <div
        id="avatar"
        class="avatar realtime-avatar"
        data-status="idle"
        data-mouth="closed"
        aria-live="polite"
      >
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

              <div id="avatarMouth" class="avatar-mouth"></div>
            </div>
          </div>
        </div>

        <div class="status-card">
          <div id="statusText" class="status">待機中</div>
          <div id="statusSubText" class="status-sub">
            「Realtime会話をはじめる」を押してください
          </div>

          <div class="voice-dots" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
          </div>
        </div>
      </div>

      <div class="controls realtime-controls">
        <button id="connectBtn" type="button">
          Realtime会話をはじめる
        </button>

        <button id="disconnectBtn" type="button" class="secondary" disabled>
          会話を終わる
        </button>

        <button id="muteBtn" type="button" class="secondary" disabled>
          マイク停止
        </button>
      </div>

      <div id="logSaveStatus" class="log-save-status" aria-live="polite">
        ログ保存: 待機中
      </div>

      <div class="realtime-help">
        <p>
          ボタンを押すとマイク許可を求められます。
          許可後、そのまま話しかけると、きずなちゃんが音声で返事をします。
        </p>
        <p class="muted">
          会話後、家族用画面を開くとRealtime版の会話ログも同じ一覧に表示されます。
          サマリは「サマリ生成」を押すと最新ログを含めて作り直されます。
        </p>
      </div>

      <audio id="remoteAudio" autoplay playsinline hidden></audio>

      <div id="log" class="log" aria-live="polite"></div>

      <details class="maintenance-panel">
        <summary>接続ログ</summary>
        <pre id="debugLog" class="debug-log"></pre>
      </details>
    </section>
  </main>

  <script src="assets/realtime.js"></script>
</body>
</html>

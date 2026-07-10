<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

require_admin();

$pdo = db();
$people = $pdo->query('SELECT id, display_name FROM conversation_people ORDER BY id ASC')->fetchAll();

$personId = (int)($_GET['person_id'] ?? ($people[0]['id'] ?? 1));
$period = $_GET['period'] ?? 'day';
$allowedPeriods = ['day', 'week', 'month'];
$deleted = (int)($_GET['deleted'] ?? 0);

if (!in_array($period, $allowedPeriods, true)) {
    $period = 'day';
}

$baseDate = $_GET['date'] ?? date('Y-m-d');
$baseTime = strtotime($baseDate);

if ($baseTime === false) {
    $baseTime = time();
    $baseDate = date('Y-m-d');
}

if ($period === 'week') {
    $start = date('Y-m-d', strtotime('monday this week', $baseTime));
    $end = date('Y-m-d', strtotime('sunday this week', $baseTime));
} elseif ($period === 'month') {
    $start = date('Y-m-01', $baseTime);
    $end = date('Y-m-t', $baseTime);
} else {
    $start = date('Y-m-d', $baseTime);
    $end = $start;
}

$stmt = $pdo->prepare(
    'SELECT role, content, created_at FROM conversation_messages
     WHERE person_id = ?
       AND created_at >= ?
       AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
     ORDER BY created_at ASC, id ASC'
);
$stmt->execute([$personId, $start . ' 00:00:00', $end . ' 00:00:00']);
$messages = $stmt->fetchAll();

$summaryStmt = $pdo->prepare(
    'SELECT summary, updated_at FROM conversation_summaries
     WHERE person_id = ? AND period_type = ? AND period_start = ? AND period_end = ?'
);
$summaryStmt->execute([$personId, $period, $start, $end]);
$summary = $summaryStmt->fetch();
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家族用管理画面</title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
  <main class="admin">
    <header class="admin-header">
      <div>
        <h1>家族用管理画面</h1>
        <p>通常版・Realtime版の会話ログとAIサマリを確認できます。</p>
      </div>
      <div class="header-links">
        <a href="../index.php">通常会話</a>
        <a href="../realtime.php">Realtime会話</a>
        <a href="logout.php">ログアウト</a>
      </div>
    </header>

    <?php if ($deleted): ?>
      <section class="panel">
        <p class="success">削除しました。</p>
      </section>
    <?php endif; ?>

    <section class="panel">
      <form method="get" class="filters">
        <label class="field">
          会話者
          <select name="person_id">
            <?php foreach ($people as $person): ?>
              <option value="<?= h($person['id']) ?>" <?= $personId === (int)$person['id'] ? 'selected' : '' ?>>
                <?= h($person['display_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          期間
          <select name="period">
            <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>日</option>
            <option value="week" <?= $period === 'week' ? 'selected' : '' ?>>週</option>
            <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>月</option>
          </select>
        </label>
        <label class="field">
          基準日
          <input type="date" name="date" value="<?= h($baseDate) ?>">
        </label>
        <button type="submit">表示</button>
      </form>
    </section>

    <section class="panel">
      <div class="section-title">
        <h2>AIサマリ</h2>
        <form method="post" action="summary.php">
          <input type="hidden" name="person_id" value="<?= h($personId) ?>">
          <input type="hidden" name="period" value="<?= h($period) ?>">
          <input type="hidden" name="date" value="<?= h($baseDate) ?>">
          <button type="submit">サマリ生成</button>
        </form>
      </div>
      <p class="period"><?= h($start) ?> 〜 <?= h($end) ?></p>
      <?php if ($summary): ?>
        <div class="summary"><?= nl2br(h($summary['summary'])) ?></div>
        <p class="muted">更新: <?= h($summary['updated_at']) ?></p>
      <?php else: ?>
        <p class="muted">まだサマリはありません。「サマリ生成」を押してください。</p>
      <?php endif; ?>
    </section>

    <section class="panel danger-zone">
      <h2>データ削除</h2>
      <p class="muted">削除後は元に戻せません。ローカル検証用・管理者用の操作です。</p>
      <div class="maintenance-actions">
        <form method="post" action="delete_data.php" onsubmit="return confirm('表示中の期間の会話ログとサマリを削除します。元に戻せません。よろしいですか？');">
          <input type="hidden" name="person_id" value="<?= h($personId) ?>">
          <input type="hidden" name="period" value="<?= h($period) ?>">
          <input type="hidden" name="date" value="<?= h($baseDate) ?>">
          <input type="hidden" name="scope" value="period">
          <button type="submit" class="danger">表示中期間を削除</button>
        </form>

        <form method="post" action="delete_data.php" onsubmit="return confirm('この会話者の全会話ログと全サマリを削除します。元に戻せません。本当によろしいですか？');">
          <input type="hidden" name="person_id" value="<?= h($personId) ?>">
          <input type="hidden" name="period" value="<?= h($period) ?>">
          <input type="hidden" name="date" value="<?= h($baseDate) ?>">
          <input type="hidden" name="scope" value="all">
          <button type="submit" class="danger">この人の全データを削除</button>
        </form>
      </div>
    </section>

    <section class="panel">
      <h2>会話ログ</h2>
      <p class="muted">Realtime版で保存された発話も、この一覧とAIサマリ生成の対象に含まれます。</p>
      <?php if (count($messages) === 0): ?>
        <p class="muted">この期間の会話はありません。</p>
      <?php else: ?>
        <div class="admin-log">
          <?php foreach ($messages as $message): ?>
            <article class="log-row <?= h($message['role']) ?>">
              <time><?= h($message['created_at']) ?></time>
              <strong><?= $message['role'] === 'user' ? '本人' : 'AI' ?></strong>
              <p><?= nl2br(h($message['content'])) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>

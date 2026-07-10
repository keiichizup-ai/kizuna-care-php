<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/helpers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = $_POST['user'] ?? '';
    $pass = $_POST['pass'] ?? '';

    if ($user === ADMIN_USER && $pass === ADMIN_PASS) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: index.php');
        exit;
    }

    $error = 'ログイン情報が違います。';
}
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>家族用ログイン</title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
  <main class="app narrow">
    <section class="panel">
      <h1>家族用ログイン</h1>
      <?php if ($error !== ''): ?>
        <p class="error"><?= h($error) ?></p>
      <?php endif; ?>
      <form method="post" class="stack">
        <label class="field">
          ユーザー名
          <input type="text" name="user" required>
        </label>
        <label class="field">
          パスワード
          <input type="password" name="pass" required>
        </label>
        <button type="submit">ログイン</button>
      </form>
    </section>
  </main>
</body>
</html>


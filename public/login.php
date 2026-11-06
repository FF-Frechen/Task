<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/views/layout.php';

if (user_count() === 0) {
    redirect('setup.php');
}
if (current_user() !== null) {
    redirect('index.php');
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);

    if ($username === '' || $password === '' || strlen($username) > 64 || strlen($password) > 1024) {
        $error = 'Bitte Benutzername und Passwort eingeben.';
    } elseif (login_is_blocked($username)) {
        $error = 'Zu viele Fehlversuche. Bitte in ' . (int)cfg('lock_minutes') . ' Minuten erneut versuchen.';
    } else {
        $stmt = db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user) {
            $ok = password_verify($password, $user['password_hash']);
        } else {
            // Gleich viel Rechenzeit wie eine echte Prüfung, damit die Antwortzeit nichts verrät.
            password_hash($password, PASSWORD_DEFAULT);
            $ok = false;
        }
        if ($ok) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $user['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([$user['password_hash'], $user['id']]);
            }
            clear_login_failures($username);
            login_user($user, $remember);
            redirect('index.php');
        }
        record_login_failure($username);
        usleep(random_int(200000, 500000));
        $error = 'Benutzername oder Passwort ist falsch.';
    }
}

page_start('Anmelden', null, 'auth-page');
?>
<main class="auth">
  <div class="auth-card">
    <h1 class="auth-title"><span class="brand-mark" aria-hidden="true"></span><?= e(cfg('app_name')) ?></h1>
    <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="login.php" class="stack">
      <?= csrf_field() ?>
      <label>Benutzername
        <input name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus value="<?= e($username) ?>">
      </label>
      <label>Passwort
        <input name="password" type="password" autocomplete="current-password" required>
      </label>
      <label class="check"><input type="checkbox" name="remember" value="1"> Angemeldet bleiben (<?= (int)cfg('remember_days') ?> Tage)</label>
      <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
    </form>
  </div>
</main>
<?php
page_end();

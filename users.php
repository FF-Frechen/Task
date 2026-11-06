<?php
/** Mein Konto (Farbe, Passwort) und – für Admins – Benutzerverwaltung. */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/views/layout.php';

$me = require_login();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    $do = (string)($_POST['do'] ?? '');
    $targetId = (int)($_POST['user_id'] ?? 0);

    if (in_array($do, ['add', 'reset', 'delete', 'admin'], true) && !$me['is_admin']) {
        http_response_code(403);
        exit('Nur für Administratoren.');
    }

    switch ($do) {
        case 'color':
            $color = (string)($_POST['color'] ?? '');
            if (valid_color($color)) {
                $pdo->prepare('UPDATE users SET color = ? WHERE id = ?')->execute([$color, $me['id']]);
                flash('success', 'Farbe gespeichert.');
            }
            break;

        case 'password':
            $current = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['password'] ?? '');
            if (!password_verify($current, $me['password_hash'])) {
                flash('error', 'Das aktuelle Passwort ist falsch.');
            } elseif ($problem = password_problem($new, (string)($_POST['password_repeat'] ?? ''))) {
                flash('error', $problem);
            } else {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $me['id']]);
                session_regenerate_id(true);
                $_SESSION['pw_fp'] = password_fingerprint($hash);
                flash('success', 'Passwort geändert. Andere angemeldete Geräte wurden abgemeldet.');
            }
            break;

        case 'add':
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $color = (string)($_POST['color'] ?? 'blue');
            if (!valid_username($username)) {
                flash('error', 'Benutzername: 2–32 Zeichen, nur Buchstaben, Ziffern, Punkt, Minus und Unterstrich.');
            } elseif ($problem = password_problem($password, $password)) {
                flash('error', $problem);
            } else {
                $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
                $exists->execute([$username]);
                if ($exists->fetchColumn()) {
                    flash('error', 'Diesen Benutzernamen gibt es schon.');
                } else {
                    $pdo->prepare('INSERT INTO users (username, password_hash, color, is_admin) VALUES (?, ?, ?, ?)')
                        ->execute([$username, password_hash($password, PASSWORD_DEFAULT),
                            valid_color($color) ? $color : 'blue', empty($_POST['is_admin']) ? 0 : 1]);
                    flash('success', "Benutzer „{$username}“ angelegt.");
                }
            }
            break;

        case 'reset':
            $password = (string)($_POST['password'] ?? '');
            if ($targetId === $me['id']) {
                flash('error', 'Dein eigenes Passwort änderst du oben unter „Mein Konto“.');
            } elseif ($problem = password_problem($password, $password)) {
                flash('error', $problem);
            } else {
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $targetId]);
                flash('success', 'Passwort gesetzt. Der Benutzer wurde überall abgemeldet.');
            }
            break;

        case 'admin':
            if ($targetId === $me['id']) {
                flash('error', 'Du kannst dir die Admin-Rechte nicht selbst entziehen.');
            } else {
                $pdo->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$targetId]);
                flash('success', 'Rechte geändert.');
            }
            break;

        case 'delete':
            if ($targetId === $me['id']) {
                flash('error', 'Du kannst dich nicht selbst löschen.');
            } else {
                $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$targetId]);
                flash('success', 'Benutzer gelöscht. Seine Aufgaben bleiben erhalten.');
            }
            break;
    }
    redirect('users.php');
}

$users = $pdo->query(
    "SELECT u.id, u.username, u.color, u.is_admin,
            (SELECT COUNT(*) FROM task_users tu JOIN tasks t ON t.id = tu.task_id
              WHERE tu.user_id = u.id AND t.status != 'done') AS open_tasks
       FROM users u ORDER BY u.username COLLATE NOCASE"
)->fetchAll();

page_start('Konto & Benutzer', $me);
?>
<main class="page">
  <p><a href="index.php" class="back">← Zurück zum Board</a></p>
  <?php render_flash(flash()); ?>

  <section class="panel">
    <h2>Mein Konto</h2>
    <form method="post" action="users.php" class="stack">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="color">
      <div class="field">
        <span class="field-label">Meine Farbe</span>
        <?php color_picker('color', $me['color']); ?>
      </div>
      <div><button type="submit" class="btn">Farbe speichern</button></div>
    </form>
    <hr>
    <form method="post" action="users.php" class="stack narrow">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="password">
      <input type="text" name="username" value="<?= e($me['username']) ?>" autocomplete="username" hidden>
      <label>Aktuelles Passwort
        <input name="current" type="password" required autocomplete="current-password">
      </label>
      <label>Neues Passwort (mind. <?= MIN_PASSWORD_LENGTH ?> Zeichen)
        <input name="password" type="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
      </label>
      <label>Neues Passwort wiederholen
        <input name="password_repeat" type="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
      </label>
      <div><button type="submit" class="btn">Passwort ändern</button></div>
    </form>
  </section>

  <section class="panel">
    <h2>Benutzer</h2>
    <ul class="user-list">
      <?php foreach ($users as $u): ?>
      <li class="user-row">
        <span class="avatar k-<?= e($u['color']) ?>"><?= e(initials($u['username'])) ?></span>
        <span class="user-name">
          <?= e($u['username']) ?>
          <?php if ($u['is_admin']): ?><span class="badge">Admin</span><?php endif; ?>
          <?php if ((int)$u['id'] === $me['id']): ?><span class="badge badge-soft">Du</span><?php endif; ?>
        </span>
        <span class="muted small"><?= (int)$u['open_tasks'] ?> offen</span>
        <?php if ($me['is_admin'] && (int)$u['id'] !== $me['id']): ?>
        <details class="user-actions">
          <summary class="btn btn-ghost btn-sm">Verwalten</summary>
          <div class="user-actions-body">
            <form method="post" action="users.php" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="reset">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input name="password" type="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" placeholder="Neues Passwort" autocomplete="new-password" aria-label="Neues Passwort für <?= e($u['username']) ?>">
              <button type="submit" class="btn btn-sm">Passwort setzen</button>
            </form>
            <div class="inline-form">
              <form method="post" action="users.php">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="admin">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-sm"><?= $u['is_admin'] ? 'Admin-Rechte entziehen' : 'Zum Admin machen' ?></button>
              </form>
              <form method="post" action="users.php" class="js-confirm" data-confirm="Benutzer „<?= e($u['username']) ?>“ wirklich löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
              </form>
            </div>
          </div>
        </details>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($me['is_admin']): ?>
    <hr>
    <h3>Neuen Benutzer anlegen</h3>
    <form method="post" action="users.php" class="stack narrow">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="add">
      <label>Benutzername
        <input name="username" required maxlength="32" autocomplete="off" autocapitalize="none" spellcheck="false">
      </label>
      <label>Startpasswort (mind. <?= MIN_PASSWORD_LENGTH ?> Zeichen)
        <input name="password" type="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
      </label>
      <div class="field">
        <span class="field-label">Farbe</span>
        <?php color_picker('color', 'green'); ?>
      </div>
      <label class="check"><input type="checkbox" name="is_admin" value="1"> Administrator</label>
      <div><button type="submit" class="btn btn-primary">Benutzer anlegen</button></div>
    </form>
    <?php endif; ?>
  </section>
</main>
<?php
page_end(['users.js']);

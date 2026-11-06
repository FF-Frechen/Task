<?php
/**
 * Ersteinrichtung: legt den ersten Benutzer (Admin) an.
 * Schutz: Nur möglich, solange es keinen Benutzer gibt, und nur mit dem
 * Einrichtungscode aus der Datei data/SETUP-CODE (liegt nicht im Web).
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/views/layout.php';

if (user_count() > 0) {
    redirect('login.php');
}

$codeFile = data_dir() . '/SETUP-CODE';
if (!is_file($codeFile)) {
    $raw = strtoupper(bin2hex(random_bytes(6)));
    $code = implode('-', str_split($raw, 4));
    file_put_contents($codeFile, $code . "\n", LOCK_EX);
    @chmod($codeFile, 0600);
}

function normalize_code(string $code): string
{
    return strtoupper(preg_replace('/[^0-9a-f]/i', '', $code) ?? '');
}

$error = null;
$username = '';
$color = 'blue';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    $code = (string)($_POST['code'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $repeat = (string)($_POST['password_repeat'] ?? '');
    $color = (string)($_POST['color'] ?? 'blue');

    if (login_is_blocked('#setup')) {
        $error = 'Zu viele Fehlversuche. Bitte später erneut versuchen.';
    } elseif (!hash_equals(normalize_code((string)file_get_contents($codeFile)), normalize_code($code))) {
        record_login_failure('#setup');
        $error = 'Der Einrichtungscode ist falsch.';
    } elseif (!valid_username($username)) {
        $error = 'Benutzername: 2–32 Zeichen, nur Buchstaben, Ziffern, Punkt, Minus und Unterstrich.';
    } elseif ($problem = password_problem($password, $repeat)) {
        $error = $problem;
    } else {
        if (!valid_color($color)) {
            $color = 'blue';
        }
        $pdo = db();
        $pdo->exec('BEGIN IMMEDIATE');
        if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            $pdo->exec('ROLLBACK');
            redirect('login.php');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('INSERT INTO users (username, password_hash, color, is_admin) VALUES (?, ?, ?, 1)')
            ->execute([$username, $hash, $color]);
        $id = (int)$pdo->lastInsertId();
        $pdo->exec('COMMIT');
        @unlink($codeFile);
        clear_login_failures('#setup');
        login_user(['id' => $id, 'password_hash' => $hash], false);
        flash('success', 'Willkommen! Lege jetzt unten die weiteren Benutzer an.');
        redirect('users.php');
    }
}

page_start('Ersteinrichtung', null, 'auth-page');
?>
<main class="auth">
  <div class="auth-card auth-card-wide">
    <h1 class="auth-title"><span class="brand-mark" aria-hidden="true"></span>Ersteinrichtung</h1>
    <p class="muted">Lege den ersten Benutzer an. Er wird Administrator und kann danach weitere Benutzer anlegen.</p>
    <p class="hint">Den <strong>Einrichtungscode</strong> findest du in der Datei <code>SETUP-CODE</code>
      <?= e(data_location_hint()) ?>. Die Datei wird nach der Einrichtung automatisch gelöscht.</p>
    <?php if ($error): ?><p class="alert alert-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="setup.php" class="stack">
      <?= csrf_field() ?>
      <label>Einrichtungscode
        <input name="code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX">
      </label>
      <label>Benutzername
        <input name="username" required autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="32" value="<?= e($username) ?>">
      </label>
      <label>Passwort (mind. <?= MIN_PASSWORD_LENGTH ?> Zeichen)
        <input name="password" type="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
      </label>
      <label>Passwort wiederholen
        <input name="password_repeat" type="password" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
      </label>
      <div class="field">
        <span class="field-label">Deine Farbe</span>
        <?php color_picker('color', $color); ?>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Einrichten</button>
    </form>
  </div>
</main>
<?php
page_end();

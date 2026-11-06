<?php
declare(strict_types=1);

/** Kopf jeder Seite. $user = angemeldeter Benutzer oder null (Login/Setup). */
function page_start(string $title, ?array $user = null, string $bodyClass = ''): void
{
    $app = (string)cfg('app_name');
    ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#1a1e25" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= e($app) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?= e($title === $app ? $app : "$title · $app") ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.json">
<link rel="stylesheet" href="assets/app.css?v=<?= e(asset_version('app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($user !== null): ?>
<header class="topbar">
  <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true"></span><?= e($app) ?></a>
  <nav class="topnav">
    <a href="users.php" class="me" title="Mein Konto &amp; Benutzer">
      <span class="avatar k-<?= e($user['color']) ?>"><?= e(initials($user['username'])) ?></span>
      <span class="me-name"><?= e($user['username']) ?></span>
    </a>
    <form method="post" action="logout.php">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-ghost">Abmelden</button>
    </form>
  </nav>
</header>
<?php if ($user['is_admin'] && ($warning = docroot_warning())): ?>
<p class="banner"><?= e($warning) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php
}

function page_end(array $scripts = []): void
{
    foreach ($scripts as $script) {
        echo '<script src="assets/' . e($script) . '?v=' . e(asset_version($script)) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

function initials(string $name): string
{
    $first = function_exists('mb_substr') ? mb_substr($name, 0, 2, 'UTF-8') : substr($name, 0, 2);
    return function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first);
}

function asset_version(string $file): string
{
    $path = APP_DIR . '/public/assets/' . $file;
    return is_file($path) ? (string)filemtime($path) : '1';
}

function flash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null) {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function render_flash(?array $flash): void
{
    if ($flash) {
        echo '<p class="alert alert-' . e($flash['type']) . '" role="alert">' . e($flash['message']) . '</p>';
    }
}

function color_picker(string $name, string $selected, bool $allowNone = false): void
{
    echo '<div class="swatches" role="radiogroup">';
    if ($allowNone) {
        $checked = $selected === '' ? ' checked' : '';
        echo '<label class="swatch swatch-none" title="Keine"><input type="radio" name="' . e($name)
            . '" value=""' . $checked . '><span></span></label>';
    }
    foreach (COLORS as $key => $label) {
        $checked = $selected === $key ? ' checked' : '';
        echo '<label class="swatch k-' . e($key) . '" title="' . e($label) . '"><input type="radio" name="'
            . e($name) . '" value="' . e($key) . '"' . $checked . ' aria-label="' . e($label)
            . '"><span></span></label>';
    }
    echo '</div>';
}

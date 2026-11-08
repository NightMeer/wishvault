<?php
declare(strict_types=1);

function layout_header(string $title, string $active = '', array $opts = []): void
{
    $csrf = csrf_token(); // Session vor jeglicher Ausgabe starten
    $site = (string)cfg('site_name');
    $user = ($opts['admin'] ?? false) ? Auth::user() : null;
    $admin = $user !== null; // Admin-Navigation nur für eingeloggte Admins (nicht auf der Login-Seite)
    $nav = $admin
        ? ['dashboard' => ['/admin/', 'Wünsche'], 'library' => ['/admin/library.php', 'Bibliothek'], 'gse' => ['/admin/gse.php', 'GSE-Spieler']]
          + (($user['super'] ?? false) ? ['admins' => ['/admin/admins.php', 'Admins']] : [])
        : ['wishlist' => ['/', 'Wunschliste'], 'gse' => ['/gse.php', 'LAN-Konfigurator']];
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> · <?= h($site) ?></title>
    <link rel="stylesheet" href="<?= h(asset('/assets/style.css')) ?>">
    <?php $fav = (string)cfg('favicon'); $favType = match (strtolower(pathinfo(parse_url($fav, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION))) {
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'jpg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', default => '' }; ?>
    <link rel="icon" href="<?= h(str_starts_with($fav, '/') ? asset($fav) : $fav) ?>"<?= $favType ? ' type="' . $favType . '"' : '' ?>>
    <link rel="apple-touch-icon" href="<?= h(str_starts_with($fav, '/') ? asset($fav) : $fav) ?>">
    <meta name="theme-color" content="#171a21">
    <meta name="description" content="<?= h((string)cfg('site_tagline')) ?>">
    <meta name="csrf-token" content="<?= h($csrf) ?>">
</head>
<body class="<?= $admin ? 'is-admin' : '' ?>">
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="/">
            <?php $logo = (string)cfg('logo'); ?>
            <span class="brand-logo"><img src="<?= h(str_starts_with($logo, '/') ? asset($logo) : $logo) ?>" alt=""></span>
            <span class="brand-text"><?= h($site) ?><?php if ($admin): ?> <small>Admin</small><?php endif; ?></span>
        </a>
        <nav class="nav">
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= h($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="nav-right">
            <?php if ($admin): ?>
                <span class="nav-user"><?= h($user['username']) ?></span>
                <a href="/" class="btn btn-ghost btn-sm">Zur Seite</a>
                <form method="post" action="/admin/login.php" class="inline">
                    <?= csrf_field() ?><input type="hidden" name="logout" value="1">
                    <button class="btn btn-ghost btn-sm">Logout</button>
                </form>
            <?php elseif (($opts['admin'] ?? false) === false): ?>
                <a href="/admin/" class="admin-link" title="Adminbereich">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5l-8-3Z"/><path d="M12 8.5a2 2 0 1 1 0 4 2 2 0 0 1 0-4Zm-3.5 7.5c.6-1.6 2-2.5 3.5-2.5s2.9.9 3.5 2.5"/></svg>
                    <span>Admin</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
<main class="container">
<?php foreach (flash() as [$type, $msg]): ?>
    <div class="alert alert-<?= h($type) ?>"><?= h($msg) ?></div>
<?php endforeach;
}

/** Asset-URL mit Änderungszeitpunkt (Cache-Busting) */
function asset(string $path): string
{
    $file = ($_SERVER['DOCUMENT_ROOT'] ?? '/var/www/html') . $path;
    return $path . '?v=' . (is_file($file) ? filemtime($file) : '1');
}

/** Steam-Logo als Inline-SVG */
function steam_icon(): string
{
    return '<svg class="steam-icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M11.979 0C5.678 0 .511 4.86.022 11.037l6.432 2.658c.545-.371 1.203-.59 1.912-.59.063 0 .125.004.188.006l2.861-4.142V8.91c0-2.495 2.028-4.524 4.524-4.524 2.494 0 4.524 2.031 4.524 4.527s-2.03 4.525-4.524 4.525h-.105l-4.076 2.911c0 .052.004.105.004.159 0 1.875-1.515 3.396-3.39 3.396-1.635 0-3.016-1.173-3.331-2.727L.436 15.27C1.862 20.307 6.486 24 11.979 24c6.627 0 11.999-5.373 11.999-12S18.605 0 11.979 0zM7.54 18.21l-1.473-.61c.262.543.714.999 1.314 1.25 1.297.539 2.793-.076 3.332-1.375.263-.63.264-1.319.005-1.949s-.75-1.121-1.377-1.383c-.624-.26-1.29-.249-1.878-.03l1.523.63c.956.4 1.409 1.5 1.009 2.455-.397.957-1.497 1.41-2.454 1.012H7.54zm11.415-9.303c0-1.662-1.353-3.015-3.015-3.015-1.665 0-3.015 1.353-3.015 3.015 0 1.665 1.35 3.015 3.015 3.015 1.663 0 3.015-1.35 3.015-3.015zm-5.273-.005c0-1.252 1.013-2.266 2.265-2.266 1.249 0 2.266 1.014 2.266 2.266 0 1.251-1.017 2.265-2.266 2.265-1.253 0-2.265-1.014-2.265-2.265z"/></svg>';
}

function layout_footer(array $scripts = []): void
{
    ?>
</main>
<footer class="footer">
    <span><?= h((string)cfg('site_name')) ?></span>
    <span class="muted">Daten von Steam &amp; GameVault · nicht mit Valve verbunden</span>
</footer>
<?php foreach ($scripts as $s): ?>
<script src="<?= h(asset($s)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['logout'])) {
        Auth::logout();
        redirect('/admin/login.php');
    }
    $error = Auth::attempt(trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''));
    if ($error === null) {
        redirect('/admin/');
    }
}
if (Auth::user()) {
    redirect('/admin/');
}
layout_header('Admin-Login', '', ['admin' => true]);
?>
<section class="panel panel-narrow">
    <span class="eyebrow">Adminbereich</span>
    <h1>Anmelden</h1>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <div class="field"><label>Benutzername</label><input name="username" required autofocus autocomplete="username"></div>
        <div class="field"><label>Passwort</label><input type="password" name="password" required autocomplete="current-password"></div>
        <button class="btn btn-primary btn-block">Einloggen</button>
    </form>
</section>
<?php
layout_footer();

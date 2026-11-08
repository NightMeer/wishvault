<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';
$admin = Auth::require(true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $name = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    try {
        if ($action === 'create') {
            if (!preg_match('/^[\p{L}\p{N}_.\-]{3,32}$/u', $name)) {
                throw new InvalidArgumentException('Benutzername: 3–32 Zeichen (Buchstaben, Ziffern, _ . -).');
            }
            if (mb_strtolower($name) === mb_strtolower(Auth::superUser())) {
                throw new InvalidArgumentException('Dieser Name ist für den Superadmin reserviert.');
            }
            if (strlen($pass) < 8) {
                throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen haben.');
            }
            db()->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)')
                ->execute([$name, password_hash($pass, PASSWORD_DEFAULT), now()]);
            flash('success', "Admin „{$name}“ angelegt.");
        } elseif ($action === 'password') {
            if (strlen($pass) < 8) {
                throw new InvalidArgumentException('Das Passwort muss mindestens 8 Zeichen haben.');
            }
            db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$_POST['id']]);
            flash('success', 'Passwort geändert.');
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM admins WHERE id = ?')->execute([(int)$_POST['id']]);
            flash('success', 'Admin gelöscht.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (PDOException $e) {
        flash('error', 'Ein Admin mit diesem Namen existiert bereits.');
    }
    redirect('/admin/admins.php');
}

$admins = db()->query('SELECT id, username, created_at FROM admins ORDER BY username COLLATE NOCASE')->fetchAll();
layout_header('Admins', 'admins', ['admin' => true]);
?>
<div class="page-head">
    <h1>Admins</h1>
    <p class="muted small">Der Superadmin <b><?= h(Auth::superUser()) ?></b> wird über die Umgebungsvariablen <code>ADMIN_USER</code>/<code>ADMIN_PASS</code> verwaltet.
        Weitere Admins können Wünsche, Bibliothek und GSE-Spieler verwalten, aber keine Admins anlegen.</p>
</div>

<form method="post" class="toolbar">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <input name="username" placeholder="Benutzername" required autocomplete="off">
    <input type="password" name="password" placeholder="Passwort (min. 8 Zeichen)" required autocomplete="new-password" minlength="8">
    <button class="btn btn-primary">+ Admin anlegen</button>
</form>

<div class="table-wrap">
<table class="table" data-sortable>
    <thead><tr><th data-sort="text">Benutzername</th><th data-sort="text">Angelegt</th><th data-nosort>Neues Passwort</th><th data-nosort></th></tr></thead>
    <tbody>
    <tr><td class="font-bold"><?= h(Auth::superUser()) ?></td><td class="muted small">Umgebung</td><td><span class="badge badge-warn">Superadmin</span></td><td></td></tr>
    <?php foreach ($admins as $a): ?>
        <tr>
            <td class="font-bold"><?= h($a['username']) ?></td>
            <td class="muted small"><?= h(fmt_date($a['created_at'])) ?></td>
            <td>
                <form method="post" class="inline-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <input type="password" name="password" placeholder="Neues Passwort" class="input-sm" minlength="8" required autocomplete="new-password">
                    <button class="btn btn-sm btn-ghost">Setzen</button>
                </form>
            </td>
            <td class="actions">
                <form method="post" class="inline" data-confirm="Admin „<?= h($a['username']) ?>“ löschen?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                    <button class="btn-icon danger" title="Löschen">🗑</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php
layout_footer(['/assets/admin.js']);

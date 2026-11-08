<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';
$admin = Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                $u = Gse::create((string)($_POST['username'] ?? ''));
                flash('success', "Spieler „{$u['username']}“ mit SteamID {$u['steam_id']} angelegt.");
                break;
            case 'rename':
                Gse::rename((int)$_POST['id'], (string)($_POST['username'] ?? ''));
                flash('success', 'Name geändert.');
                break;
            case 'remove':
                Gse::remove((int)$_POST['id']);
                flash('success', 'Spieler entfernt. Die SteamID bleibt dauerhaft reserviert.');
                break;
            case 'sync':
                flash('info', 'GameVault-Sync: ' . Jobs::syncGameVault());
                break;
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        flash('error', 'Fehler: ' . $e->getMessage());
    }
    redirect('/admin/gse.php');
}

$users = Gse::activeUsers();
layout_header('GSE-Spieler', 'gse', ['admin' => true]);
?>
<div class="page-head">
    <h1>GSE-Spieler <span class="muted">(<?= count($users) ?>)</span></h1>
    <p class="muted small">Aktivierte GameVault-Nutzer werden beim Sync automatisch übernommen. Zusätzliche Spieler kannst du hier manuell anlegen.
        Vergebene SteamIDs werden nie neu vergeben.</p>
</div>

<div class="toolbar">
    <form method="post" class="input-addon">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <input name="username" id="gseFilter" placeholder="Name suchen oder neuen Spieler anlegen …" autocomplete="off" required maxlength="32">
        <button class="btn btn-primary">+ Anlegen</button>
    </form>
    <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="sync">
        <button class="btn btn-ghost">⟳ GameVault-Nutzer abgleichen</button>
    </form>
</div>

<div class="table-wrap">
<table class="table" id="gseTable" data-sortable>
    <thead><tr><th data-sort="text">Spielername</th><th data-sort="num">SteamID64</th><th data-sort="text">Quelle</th><th data-sort="num">Angelegt</th><th data-nosort></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr data-name="<?= h(mb_strtolower((string)$u['username'])) ?>">
            <td class="font-bold"><?= h($u['username']) ?></td>
            <td><code><?= h($u['steam_id']) ?></code></td>
            <td><?= $u['source'] === 'gamevault' ? '<span class="badge badge-queued">GameVault</span>' : '<span class="badge badge-new">Manuell</span>' ?></td>
            <td class="small muted" data-value="<?= (int)strtotime((string)$u['created_at']) ?>"><?= h(fmt_date($u['created_at'])) ?></td>
            <td class="actions">
                <button type="button" class="btn-icon" data-rename="<?= (int)$u['id'] ?>" data-name="<?= h($u['username']) ?>" title="Umbenennen">✎</button>
                <form method="post" class="inline" data-confirm="„<?= h($u['username']) ?>“ entfernen? Die SteamID bleibt reserviert.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                    <button class="btn-icon danger" title="Entfernen">🗑</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr id="gseNoMatch" data-static hidden><td colspan="5" class="empty">Kein Spieler gefunden – mit <b>Enter</b> neu anlegen.</td></tr>
    </tbody>
</table>
</div>

<dialog id="renameDialog" class="modal">
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" id="renameId">
        <h3>Spieler umbenennen</h3>
        <p class="muted small">Die SteamID bleibt unverändert. Hinweis: GameVault-Nutzer werden beim nächsten Sync wieder auf ihren GameVault-Namen gesetzt.</p>
        <div class="field"><label>Spielername</label><input name="username" id="renameInput" required maxlength="32"></div>
        <div class="btn-row end">
            <button type="button" class="btn btn-ghost" data-close>Abbrechen</button>
            <button class="btn btn-primary">Speichern</button>
        </div>
    </form>
</dialog>
<?php
layout_footer(['/assets/admin.js']);

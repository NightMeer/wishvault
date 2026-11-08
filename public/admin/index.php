<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';
$admin = Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch ($_POST['action'] ?? '') {
            case 'status':
                $status = (string)($_POST['status'] ?? '');
                $reason = trim((string)($_POST['reason'] ?? ''));
                $game = Wishes::byId($id);
                if ($game && ($game['status'] !== $status || ($status === 'rejected' && $reason !== (string)$game['reject_reason']))) {
                    $notify = $game['status'] !== $status && !empty($_POST['notify']) && Mailer::isConfigured();
                    Wishes::setStatus($id, $status, $notify, $reason);
                    if ($status === 'available' && $notify) {
                        Notifier::processQueue(20);
                    }
                    flash('success', "Status von „{$game['name']}“ auf " . STATUS_LABELS[$status] . ' gesetzt'
                        . ($status === 'rejected' && $reason !== '' ? " (Grund: {$reason})" : '') . '.');
                }
                break;
            case 'note':
                db()->prepare('UPDATE games SET admin_note = ? WHERE id = ?')->execute([trim((string)($_POST['note'] ?? '')) ?: null, $id]);
                flash('success', 'Notiz gespeichert.');
                break;
            case 'delete':
                Wishes::delete($id);
                flash('success', 'Wunsch gelöscht.');
                break;
            case 'sync':
                set_time_limit(300);
                foreach (Jobs::runAll(!empty($_POST['force'])) as $line) {
                    flash(str_contains($line, 'FEHLER') ? 'error' : 'info', $line);
                }
                break;
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('/admin/' . (isset($_GET['f']) ? '?f=' . urlencode((string)$_GET['f']) : ''));
}

$filter = (string)($_GET['f'] ?? 'open');
$where = match ($filter) {
    'all' => '1=1',
    'new', 'queued', 'paused', 'rejected', 'available' => 'g.status = ' . db()->quote($filter),
    default => "g.status IN ('new','queued','paused')",
};
$games = db()->query("SELECT g.*, gv.title AS gv_title, gv.version AS gv_version, gv.file_changed_at, gv.steam_timeupdated, gv.ack_timeupdated, gv.id AS gv_id, gv.cover_media_id,
        (SELECT COUNT(*) FROM votes v WHERE v.game_id = g.id AND v.email IS NOT NULL) AS email_count,
        (SELECT COUNT(*) FROM notifications n WHERE n.game_id = g.id AND n.sent_at IS NOT NULL) AS sent_count
    FROM games g LEFT JOIN gv_games gv ON gv.id = g.gv_game_id
    WHERE {$where} ORDER BY g.votes DESC, g.last_vote_at DESC")->fetchAll();

$counts = db()->query('SELECT status, COUNT(*) c FROM games GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingMails = (int)db()->query('SELECT COUNT(*) FROM notifications WHERE sent_at IS NULL AND attempts < 5')->fetchColumn();
$failedMails = (int)db()->query('SELECT COUNT(*) FROM notifications WHERE sent_at IS NULL AND attempts >= 5')->fetchColumn();
$updates = count(Jobs::updatesAvailable());
$mailEnabled = Mailer::isConfigured();

layout_header('Wünsche', 'dashboard', ['admin' => true]);
?>
<div class="stats">
    <a class="stat" href="?f=new"><b><?= (int)($counts['new'] ?? 0) ?></b><span>Neu</span></a>
    <a class="stat" href="?f=queued"><b><?= (int)($counts['queued'] ?? 0) ?></b><span>Warteschlange</span></a>
    <a class="stat" href="?f=paused"><b><?= (int)($counts['paused'] ?? 0) ?></b><span>Pausiert</span></a>
    <a class="stat" href="?f=available"><b><?= (int)($counts['available'] ?? 0) ?></b><span>Verfügbar</span></a>
    <a class="stat <?= $updates ? 'stat-warn' : '' ?>" href="/admin/library.php?f=updates"><b><?= $updates ?></b><span>Updates verfügbar</span></a>
    <?php if ($mailEnabled): ?>
    <div class="stat"><b><?= $pendingMails ?><?= $failedMails ? " <small class='text-danger'>/ {$failedMails} ✕</small>" : '' ?></b><span>Mails offen</span></div>
    <?php endif; ?>
</div>

<div class="sysinfo">
    <span>GameVault: <?= GameVault::isConfigured() ? '<b class="ok">verbunden</b>' : '<b class="text-danger">nicht konfiguriert</b>' ?></span>
    <span>SMTP: <?= Mailer::isConfigured() ? '<b class="ok">aktiv</b>' : '<b class="text-danger">nicht konfiguriert</b>' ?></span>
    <span>Letzter Sync: <?= h(fmt_date(setting('last_gv_sync'))) ?></span>
    <span>Letzter Cron: <?= h(fmt_date(setting('last_cron_run'))) ?></span>
    <form method="post" class="inline">
        <?= csrf_field() ?><input type="hidden" name="action" value="sync">
        <button class="btn btn-primary btn-sm">⟳ Jetzt synchronisieren</button>
        <label class="check small"><input type="checkbox" name="force" value="1"> Steam komplett neu prüfen</label>
    </form>
</div>

<div class="table-tools">
<div class="tabs tabs-links">
    <?php foreach (['open' => 'Offen', 'new' => 'Neu', 'queued' => 'Warteschlange', 'paused' => 'Pausiert', 'available' => 'Verfügbar', 'rejected' => 'Abgelehnt', 'all' => 'Alle'] as $k => $l): ?>
        <a class="tab <?= $filter === $k ? 'active' : '' ?>" href="?f=<?= $k ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>
<input type="search" class="table-filter" data-table="#wishTable" placeholder="Tabelle filtern …">
</div>

<div class="table-wrap">
<table class="table" id="wishTable" data-sortable>
    <thead><tr><th data-nosort></th><th data-sort="text">Spiel</th><th class="num" data-sort="num">Votes</th><th data-sort="text">Status</th><th data-sort="text">GameVault</th><th data-sort="text">Notiz</th><th data-nosort></th></tr></thead>
    <tbody>
    <?php if (!$games): ?><tr data-static><td colspan="7" class="empty">Keine Einträge.</td></tr><?php endif; ?>
    <?php foreach ($games as $g): $hasUpd = $g['gv_id'] && Jobs::hasUpdate($g); ?>
        <tr>
            <td class="thumb"><img src="<?= h($g['header_image'] ?: steam_header((int)$g['appid'])) ?>" alt="" loading="lazy" onerror="this.classList.add('broken')"></td>
            <td data-value="<?= h(mb_strtolower($g['name'])) ?>">
                <div class="font-bold"><?= h($g['name']) ?></div>
                <div class="links small">
                    <a href="https://store.steampowered.com/app/<?= (int)$g['appid'] ?>" target="_blank" rel="noopener" class="icon-link" title="Steam"><?= steam_icon() ?></a>
                    <a href="https://steamdb.info/app/<?= (int)$g['appid'] ?>/patchnotes/" target="_blank" rel="noopener">SteamDB</a>
                    <?php if ($gvUrl = GameVault::gameUrl($g['gv_id'] ? (int)$g['gv_id'] : null)): ?><a href="<?= h($gvUrl) ?>" target="_blank" rel="noopener">GameVault</a><?php endif; ?>
                    <button type="button" class="link-btn" data-tags="<?= (int)$g['appid'] ?>" data-name="<?= h($g['name']) ?>">🏷 Tags</button>
                    <span class="muted">#<?= (int)$g['appid'] ?></span>
                    <?php if ($g['coming_soon']): ?><span class="badge badge-paused">Coming Soon <?= h($g['release_date']) ?></span><?php endif; ?>
                </div>
                <div class="muted small">gewünscht <?= h(fmt_date($g['created_at'])) ?><?php if ($mailEnabled): ?> · <?= (int)$g['email_count'] ?> E-Mail(s)<?= $g['sent_count'] ? ' · ' . (int)$g['sent_count'] . ' benachrichtigt' : '' ?><?php endif; ?></div>
            </td>
            <td class="num font-bold"><?= (int)$g['votes'] ?></td>
            <td data-value="<?= h(STATUS_LABELS[$g['status']] ?? $g['status']) ?>">
                <form method="post" class="status-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                    <select name="status" class="status-select status-<?= h($g['status']) ?>">
                        <?php foreach (STATUS_LABELS as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $g['status'] === $k ? 'selected' : '' ?>><?= h($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input name="reason" class="input-sm reason-input" maxlength="300" value="<?= h($g['reject_reason']) ?>"
                           placeholder="Ablehnungsgrund (öffentlich sichtbar)" <?= $g['status'] === 'rejected' ? '' : 'hidden' ?>>
                    <?php if ($mailEnabled): ?><label class="check small notify-check" hidden><input type="checkbox" name="notify" value="1" checked> Interessenten mailen</label><?php endif; ?>
                    <button class="btn btn-sm btn-primary save-btn" hidden>Speichern</button>
                </form>
            </td>
            <td class="small">
                <?php if ($g['gv_id']): ?>
                    <div>✔ <?= h($g['gv_title']) ?><?= $g['gv_version'] ? ' <code>' . h($g['gv_version']) . '</code>' : '' ?></div>
                    <div class="muted">Abgleich: <?= $g['gv_match'] === 'appid' ? 'Steam-AppID' : 'Titel' ?></div>
                    <?php if ($hasUpd): ?><span class="badge badge-warn">⚠ Update verfügbar</span><?php endif; ?>
                <?php else: ?><span class="muted">nicht gefunden</span><?php endif; ?>
            </td>
            <td data-value="<?= h(mb_strtolower((string)$g['admin_note'])) ?>">
                <form method="post" class="note-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                    <input name="note" value="<?= h($g['admin_note']) ?>" placeholder="z. B. wartet auf Crack" class="input-sm">
                </form>
            </td>
            <td class="actions">
                <form method="post" class="inline" data-confirm="Wunsch „<?= h($g['name']) ?>“ inkl. aller Votes löschen?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
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

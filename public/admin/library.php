<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';
$admin = Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'ack':
            // Aktuellen Steam-Stand als "in GameVault vorhanden" bestätigen
            db()->prepare('UPDATE gv_games SET ack_timeupdated = steam_timeupdated WHERE id = ?')->execute([$id]);
            flash('success', 'Als aktuell markiert.');
            break;
        case 'appid':
            $raw = trim((string)($_POST['appid'] ?? ''));
            $appid = $raw === '' ? null : Steam::parseAppId($raw);
            if ($raw !== '' && !$appid) {
                flash('error', 'Ungültige AppID bzw. ungültiger Steam-Link.');
                break;
            }
            db()->prepare('UPDATE gv_games SET appid_override = ?, appid = COALESCE(?, appid), steam_checked_at = NULL, steam_timeupdated = NULL, ack_timeupdated = NULL WHERE id = ?')
                ->execute([$appid, $appid, $id]);
            if ($appid) {
                $info = Steam::buildInfo($appid);
                db()->prepare('UPDATE gv_games SET steam_buildid = ?, steam_timeupdated = ?, steam_checked_at = ? WHERE id = ?')
                    ->execute([$info['buildid'] ?? null, $info['timeupdated'] ?? null, now(), $id]);
            }
            flash('success', $appid ? "Steam-AppID {$appid} zugeordnet." : 'Manuelle Zuordnung entfernt (wird beim nächsten Sync neu erkannt).');
            break;
    }
    redirect('/admin/library.php' . (isset($_GET['f']) ? '?f=' . urlencode((string)$_GET['f']) : ''));
}

$filter = (string)($_GET['f'] ?? 'all');
$rows = db()->query('SELECT gv.*, (SELECT g.name FROM games g WHERE g.gv_game_id = gv.id LIMIT 1) AS wish_name
                     FROM gv_games gv WHERE gv.removed = 0 ORDER BY gv.title COLLATE NOCASE')->fetchAll();
$rows = array_values(array_filter($rows, fn($r) => match ($filter) {
    'updates' => Jobs::hasUpdate($r),
    'nosteam' => empty($r['appid']),
    default   => true,
}));

layout_header('GameVault-Bibliothek', 'library', ['admin' => true]);
?>
<div class="page-head">
    <h1>GameVault-Bibliothek <span class="muted">(<?= count($rows) ?>)</span></h1>
    <p class="muted small">Versionsabgleich: Steht eine Steam-Build-ID im Dateinamen (z. B. <code>Spiel (v26327008).rar</code>), wird sie mit dem
        aktuellen Steam-Build verglichen. Sonst gilt: Liegt das letzte Steam-Update nach dem Zeitpunkt, an dem die Datei in GameVault hinzugefügt
        bzw. ausgetauscht wurde, erscheint „Update verfügbar“. Mit „Ist aktuell“ bestätigst du den aktuellen Steam-Stand.</p>
</div>
<div class="table-tools">
<div class="tabs tabs-links">
    <a class="tab <?= $filter === 'all' ? 'active' : '' ?>" href="?f=all">Alle</a>
    <a class="tab <?= $filter === 'updates' ? 'active' : '' ?>" href="?f=updates">⚠ Updates verfügbar</a>
    <a class="tab <?= $filter === 'nosteam' ? 'active' : '' ?>" href="?f=nosteam">Ohne Steam-Zuordnung</a>
</div>
<input type="search" class="table-filter" data-table="#libTable" placeholder="Tabelle filtern …">
</div>
<div class="table-wrap">
<table class="table" id="libTable" data-sortable>
    <thead><tr><th data-nosort></th><th data-sort="text">Spiel</th><th data-sort="text">Version (Datei)</th><th data-sort="num">In GameVault seit</th><th data-sort="num">Steam-Update</th><th data-sort="num">Steam-AppID</th><th data-sort="num">Status</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr data-static><td colspan="7" class="empty">Keine Einträge<?= GameVault::isConfigured() ? '' : ' – GameVault ist nicht konfiguriert' ?>.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $upd = Jobs::hasUpdate($r); $fileBuild = Jobs::fileBuildId($r); $mode = Jobs::compareMode($r); ?>
        <tr class="<?= $upd ? 'row-warn' : '' ?>">
            <td class="thumb thumb-portrait">
                <?php if ($img = gv_image($r['cover_media_id'] ? (int)$r['cover_media_id'] : null) ?? ($r['cover_url'] ?: null)): ?>
                    <img src="<?= h($img) ?>" alt="" loading="lazy" class="portrait" onerror="this.classList.add('broken')">
                <?php endif; ?>
            </td>
            <td data-value="<?= h(mb_strtolower($r['meta_title'] ?: $r['title'])) ?>">
                <div class="font-bold"><?= h($r['meta_title'] ?: $r['title']) ?></div>
                <div class="muted small" title="<?= h($r['file_path']) ?>"><?= h(basename((string)$r['file_path'])) ?></div>
                <div class="links small">
                    <a href="<?= h(GameVault::gameUrl((int)$r['id'])) ?>" target="_blank" rel="noopener">GameVault</a>
                    <?php if ($r['appid']): ?>
                        <a href="https://store.steampowered.com/app/<?= (int)$r['appid'] ?>" target="_blank" rel="noopener" class="icon-link" title="Steam"><?= steam_icon() ?></a>
                        <a href="https://steamdb.info/app/<?= (int)$r['appid'] ?>/patchnotes/" target="_blank" rel="noopener">SteamDB</a>
                        <button type="button" class="link-btn" data-tags="<?= (int)$r['appid'] ?>" data-name="<?= h($r['meta_title'] ?: $r['title']) ?>">🏷 Tags</button>
                    <?php endif; ?>
                    <?php if ($r['wish_name']): ?><span class="badge badge-available">Wunsch</span><?php endif; ?>
                </div>
            </td>
            <td data-value="<?= h((string)($r['version'] ?? '')) ?>">
                <?= $r['version'] ? '<code>' . h($r['version']) . '</code>' : '<span class="muted">–</span>' ?>
                <?php if ($fileBuild): ?><div class="muted small">Build <?= (int)$fileBuild ?></div><?php endif; ?>
            </td>
            <td class="small" data-value="<?= (int)strtotime((string)$r['file_changed_at']) ?>"><?= h(fmt_date($r['file_changed_at'])) ?></td>
            <td class="small" data-value="<?= (int)($r['steam_timeupdated'] ?? 0) ?>">
                <?php if ($r['steam_timeupdated']): ?>
                    <?= h(fmt_date((string)$r['steam_timeupdated'])) ?><br><span class="muted">Build <?= h($r['steam_buildid']) ?></span>
                    <br><span class="muted">Vergleich: <?= $mode === 'build' ? 'Build-ID' : 'Datum' ?></span>
                <?php else: ?><span class="muted"><?= $r['appid'] ? 'noch nicht geprüft' : '–' ?></span><?php endif; ?>
            </td>
            <td data-value="<?= (int)($r['appid'] ?? 0) ?>">
                <form method="post" class="inline-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="appid"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input name="appid" value="<?= h((string)($r['appid'] ?? '')) ?>" placeholder="AppID / Link" class="input-sm input-appid" title="<?= $r['appid_override'] ? 'manuell zugeordnet' : 'automatisch erkannt' ?>">
                    <?php if ($r['appid_override']): ?><span class="muted small" title="manuell zugeordnet">✎</span><?php endif; ?>
                </form>
                <?php if ($r['appid']): ?>
                    <div class="links small"><a href="https://steamdb.info/app/<?= (int)$r['appid'] ?>/patchnotes/" target="_blank" rel="noopener">SteamDB</a></div>
                <?php endif; ?>
            </td>
            <td class="actions" data-value="<?= $upd ? 1 : 0 ?>">
                <?php if ($upd): ?>
                <span class="badge badge-warn">⚠ Update verfügbar</span>
                <form method="post" class="inline">
                    <?= csrf_field() ?><input type="hidden" name="action" value="ack"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn-sm btn-ghost" title="Aktuellen Steam-Stand als vorhanden bestätigen">✔ Ist aktuell</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php
layout_footer(['/assets/admin.js']);

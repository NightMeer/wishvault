<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';

$languages = cfg('gse.languages', []);
$defaultLang = isset($languages[cfg('gse.default_language')]) ? (string)cfg('gse.default_language') : (string)array_key_first($languages);

// Live-Prüfung des Spielernamens
if (($_GET['action'] ?? '') === 'check') {
    $u = Gse::findActiveByName((string)($_GET['name'] ?? ''));
    json_out($u ? ['found' => true, 'username' => $u['username'], 'steam_id' => $u['steam_id']] : ['found' => false]);
}

// Download (.bat oder .ini)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $user = Gse::findActiveByName((string)($_POST['username'] ?? ''));
    $lang = (string)($_POST['language'] ?? $defaultLang);
    if (!isset($languages[$lang])) {
        $lang = $defaultLang;
    }
    if (!$user) {
        flash('error', 'Spieler nicht gefunden.');
        redirect('/gse.php');
    }
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $user['username']) ?: $user['steam_id'];
    if (($_POST['format'] ?? 'bat') === 'ini') {
        $content = Gse::buildIni($user, $lang);
        $filename = 'configs.user.ini';
        $type = 'text/plain; charset=utf-8';
    } else {
        $content = Gse::buildBat($user, $lang);
        $filename = "gse_setup_{$safe}.bat";
        $type = 'application/octet-stream';
    }
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store');
    echo $content;
    exit;
}

layout_header('LAN-Konfigurator', 'gse');
?>
<section class="panel panel-narrow">
    <span class="eyebrow">Goldberg Emulator · LAN</span>
    <h1>Spieler-Konfigurator</h1>
    <p class="muted">Gib deinen GameVault-Namen ein. Du erhältst eine Setup-Datei, die deine feste SteamID, deinen Namen und die Sprache
        global in <code>%APPDATA%\GSE Saves\settings\configs.user.ini</code> einträgt. Andere Einstellungen in der Datei bleiben erhalten.</p>

    <div class="field">
        <label for="gseName">Spielername (GameVault-Account)</label>
        <div class="input-status">
            <input type="text" id="gseName" autocomplete="off" placeholder="Dein Name …">
            <span class="status-dot" id="gseDot"></span>
        </div>
        <small class="muted" id="gseHint">Name eingeben und kurz warten …</small>
    </div>

    <form method="post" id="gseForm" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="username" id="gseUser">
        <div class="steamid-box">
            <span class="muted">SteamID64</span>
            <code id="gseSteamId">–</code>
        </div>
        <div class="field">
            <label for="gseLang">Spielsprache</label>
            <select name="language" id="gseLang">
                <?php foreach ($languages as $key => [$label]): ?>
                    <option value="<?= h($key) ?>" <?= $key === $defaultLang ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="btn-row">
            <button class="btn btn-primary" name="format" value="bat">⬇ Setup herunterladen (.bat)</button>
            <button class="btn btn-ghost" name="format" value="ini" title="Datei manuell ablegen">configs.user.ini</button>
        </div>
        <small class="muted">Nach dem Download die .bat per Doppelklick ausführen. Windows SmartScreen ggf. mit „Trotzdem ausführen“ bestätigen.</small>
    </form>
</section>
<?php
layout_footer(['/assets/gse.js']);

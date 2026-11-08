<?php
declare(strict_types=1);

/** Hintergrund-Aufgaben (Cron und "Jetzt synchronisieren" im Admin). */
final class Jobs
{
    /** Alle Jobs nacheinander; Rückgabe = Log-Zeilen */
    public static function runAll(bool $force = false): array
    {
        $log = [];
        foreach ([
            'GameVault-Sync'     => fn() => self::syncGameVault(),
            'Steam-Release'      => fn() => self::checkSteamReleases($force),
            'Versionsabgleich'   => fn() => self::checkVersions($force),
            'Mailversand'        => fn() => self::sendMails(),
        ] as $name => $job) {
            try {
                $log[] = "{$name}: " . $job();
            } catch (Throwable $e) {
                $log[] = "{$name}: FEHLER – " . $e->getMessage();
                log_msg("{$name}: " . $e->getMessage());
            }
        }
        Cache::purgeExpired();
        set_setting('last_cron_run', now());
        return $log;
    }

    private static function isoToLocal(?string $iso): ?string
    {
        if (!$iso) {
            return null;
        }
        $ts = strtotime($iso);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** Steam-AppID aus GameVault-Metadaten (Websites/Provider-Links) lesen */
    private static function extractAppId(array $g): ?int
    {
        $urls = array_merge(
            (array)($g['metadata']['url_websites'] ?? []),
            [(string)($g['metadata']['provider_data_url'] ?? '')],
            array_map(fn($p) => (string)($p['provider_data_url'] ?? ''), (array)($g['provider_metadata'] ?? []))
        );
        foreach ($urls as $u) {
            if (is_string($u) && preg_match('~store\.steampowered\.com/app/(\d+)~i', $u, $m)) {
                return (int)$m[1];
            }
        }
        return null;
    }

    public static function syncGameVault(): string
    {
        if (!GameVault::isConfigured()) {
            return 'übersprungen (nicht konfiguriert)';
        }
        $games = GameVault::games();
        $pdo = db();
        $seen = [];
        $new = 0;
        $pdo->beginTransaction();
        try {
            foreach ($games as $g) {
                if (!empty($g['deleted_at'])) {
                    continue;
                }
                $id = (int)$g['id'];
                $seen[] = $id;
                $versions = (array)($g['versions'] ?? []);
                $latestIndexed = null;
                foreach ($versions as $v) {
                    $t = self::isoToLocal($v['indexed_at'] ?? null);
                    if ($t && (!$latestIndexed || $t > $latestIndexed)) {
                        $latestIndexed = $t;
                    }
                }
                $version = $g['version'] ?? null;
                if (!$version && $versions) {
                    $version = end($versions)['version'] ?? null;
                }
                $fingerprint = sha1(json_encode([$g['file_path'] ?? '', $g['size'] ?? '', $version,
                    array_map(fn($v) => [$v['file_path'] ?? '', $v['size'] ?? '', $v['version'] ?? ''], $versions)]));
                $createdAt = self::isoToLocal($g['created_at'] ?? null) ?? now();
                $metaTitle = (string)($g['metadata']['title'] ?? '');
                $cover = (string)($g['metadata']['cover']['source_url'] ?? '');
                $coverId = isset($g['metadata']['cover']['id']) ? (int)$g['metadata']['cover']['id'] : null;
                $bgId = isset($g['metadata']['background']['id']) ? (int)$g['metadata']['background']['id'] : null;

                $stmt = $pdo->prepare('SELECT fingerprint, file_changed_at FROM gv_games WHERE id = ?');
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if (!$old) {
                    $changedAt = max($createdAt, $latestIndexed ?? $createdAt);
                    $new++;
                } elseif ($old['fingerprint'] !== $fingerprint) {
                    $changedAt = now(); // Datei/Version wurde ausgetauscht
                } else {
                    $changedAt = $old['file_changed_at'];
                }
                $pdo->prepare('INSERT INTO gv_games (id, title, meta_title, norm_title, norm_meta_title, appid, version, file_path, size, cover_url,
                                   fingerprint, gv_created_at, file_changed_at, removed, seen_at, cover_media_id, background_media_id)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)
                               ON CONFLICT(id) DO UPDATE SET title=excluded.title, meta_title=excluded.meta_title, norm_title=excluded.norm_title,
                                   norm_meta_title=excluded.norm_meta_title, appid=COALESCE(gv_games.appid_override, excluded.appid), version=excluded.version, file_path=excluded.file_path,
                                   size=excluded.size, cover_url=excluded.cover_url, fingerprint=excluded.fingerprint, gv_created_at=excluded.gv_created_at,
                                   file_changed_at=excluded.file_changed_at, removed=0, seen_at=excluded.seen_at,
                                   cover_media_id=excluded.cover_media_id, background_media_id=excluded.background_media_id')
                    ->execute([$id, (string)$g['title'], $metaTitle, normalize_title((string)$g['title']), $metaTitle !== '' ? normalize_title($metaTitle) : null,
                        self::extractAppId($g), $version, $g['file_path'] ?? null, (string)($g['size'] ?? ''), $cover,
                        $fingerprint, $createdAt, $changedAt, now(), $coverId, $bgId]);
            }
            $sql = 'UPDATE gv_games SET removed = 1';
            if ($seen) {
                $sql .= ' WHERE id NOT IN (' . implode(',', $seen) . ')';
            }
            $pdo->exec($sql);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Offene Wünsche mit der Bibliothek abgleichen
        $matched = 0;
        $auto = (bool)cfg('gamevault.auto_available', true);
        $notify = (bool)cfg('gamevault.notify_on_auto_available', false);
        foreach ($pdo->query("SELECT * FROM games")->fetchAll() as $w) {
            $gv = Wishes::findGvMatch((int)$w['appid'], (string)$w['name']);
            $gvId = $gv ? (int)$gv['id'] : null;
            if ($gvId !== ($w['gv_game_id'] !== null ? (int)$w['gv_game_id'] : null)) {
                $pdo->prepare('UPDATE games SET gv_game_id = ?, gv_match = ? WHERE id = ?')
                    ->execute([$gvId, $gv['match_type'] ?? null, $w['id']]);
            }
            if ($gv && $auto && in_array($w['status'], ['new', 'queued', 'paused'], true)) {
                Wishes::setStatus((int)$w['id'], 'available', $notify);
                $matched++;
            }
        }

        $userInfo = '';
        if (cfg('gamevault.sync_users', true)) {
            $created = Gse::syncFromGameVault(GameVault::users());
            $userInfo = ", {$created} neue GSE-Spieler";
        }
        set_setting('last_gv_sync', now());
        return count($seen) . " Spiele ({$new} neu), {$matched} Wünsche auf verfügbar gesetzt{$userInfo}";
    }

    public static function checkSteamReleases(bool $force = false): string
    {
        $hours = (int)cfg('steam.recheck_hours', 6);
        $limit = date('Y-m-d H:i:s', time() - $hours * 3600);
        $stmt = db()->prepare("SELECT * FROM games WHERE coming_soon = 1 AND status <> 'rejected'"
            . ($force ? '' : ' AND (steam_checked_at IS NULL OR steam_checked_at < ?)') . ' LIMIT 40');
        $stmt->execute($force ? [] : [$limit]);
        $released = 0;
        $checked = 0;
        foreach ($stmt->fetchAll() as $g) {
            $d = Steam::appDetails((int)$g['appid'], 0);
            $checked++;
            if (!$d) {
                db()->prepare('UPDATE games SET steam_checked_at = ? WHERE id = ?')->execute([now(), $g['id']]);
                continue;
            }
            db()->prepare('UPDATE games SET name = ?, coming_soon = ?, release_date = ?, header_image = ?, steam_checked_at = ? WHERE id = ?')
                ->execute([$d['name'], (int)$d['coming_soon'], $d['release_date'], $d['header_image'], now(), $g['id']]);
            if (!$d['coming_soon']) {
                Notifier::enqueue((int)$g['id'], 'released');
                $released++;
            }
            usleep(300000);
        }
        return "{$checked} geprüft, {$released} erschienen";
    }

    public static function checkVersions(bool $force = false): string
    {
        $hours = (int)cfg('steam.recheck_hours', 6);
        $limit = date('Y-m-d H:i:s', time() - $hours * 3600);
        $stmt = db()->prepare('SELECT id, appid FROM gv_games WHERE removed = 0 AND appid IS NOT NULL'
            . ($force ? '' : ' AND (steam_checked_at IS NULL OR steam_checked_at < ?)') . ' ORDER BY steam_checked_at IS NOT NULL, steam_checked_at LIMIT 60');
        $stmt->execute($force ? [] : [$limit]);
        $checked = 0;
        foreach ($stmt->fetchAll() as $g) {
            $info = Steam::buildInfo((int)$g['appid']);
            db()->prepare('UPDATE gv_games SET steam_buildid = COALESCE(?, steam_buildid), steam_timeupdated = COALESCE(?, steam_timeupdated), steam_checked_at = ? WHERE id = ?')
                ->execute([$info['buildid'] ?? null, $info['timeupdated'] ?? null, now(), $g['id']]);
            $checked++;
            usleep(250000);
        }
        $updates = count(self::updatesAvailable());
        return "{$checked} geprüft, {$updates} mit Update";
    }

    /** GameVault-Spiele, bei denen Steam ein neueres Update hat als die Datei in GameVault */
    public static function updatesAvailable(): array
    {
        return array_values(array_filter(
            db()->query('SELECT * FROM gv_games WHERE removed = 0 AND steam_timeupdated IS NOT NULL')->fetchAll(),
            fn($g) => self::hasUpdate($g)
        ));
    }

    /**
     * Steam-Build-ID aus Version bzw. Dateiname, z. B. "Spiel (v26327008).rar".
     * Nur 7–10-stellige Zahlen zählen (kürzere sind z. B. GOG-Installer-Nummern oder Jahreszahlen).
     */
    public static function fileBuildId(array $gv): ?int
    {
        foreach ([(string)($gv['version'] ?? ''), basename((string)($gv['file_path'] ?? ''))] as $s) {
            if (preg_match('/(?<![\d.])(\d{7,10})(?![\d.])/', $s, $m)) {
                return (int)$m[1];
            }
        }
        return null;
    }

    /** Vergleichsmodus: 'build' wenn Build-ID in der Datei steht und Steam eine liefert, sonst 'date' */
    public static function compareMode(array $gv): string
    {
        return (self::fileBuildId($gv) && !empty($gv['steam_buildid'])) ? 'build' : 'date';
    }

    public static function hasUpdate(array $gv): bool
    {
        if (empty($gv['steam_timeupdated'])) {
            return false;
        }
        // Manuell als aktuell bestätigt?
        if (!empty($gv['ack_timeupdated']) && (int)$gv['ack_timeupdated'] >= (int)$gv['steam_timeupdated']) {
            return false;
        }
        if (self::compareMode($gv) === 'build') {
            return (int)$gv['steam_buildid'] > (int)self::fileBuildId($gv);
        }
        return (int)$gv['steam_timeupdated'] > (int)strtotime((string)$gv['file_changed_at']);
    }

    public static function sendMails(): string
    {
        if (!Mailer::isConfigured()) {
            return 'übersprungen (SMTP nicht konfiguriert)';
        }
        [$sent, $failed] = Notifier::processQueue();
        return "{$sent} gesendet, {$failed} fehlgeschlagen";
    }
}

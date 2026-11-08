<?php
declare(strict_types=1);

/** E-Mail-Benachrichtigungen: Warteschlange mit Schutz gegen Mehrfachversand. */
final class Notifier
{
    /** Alle Interessenten (E-Mails aus Votes) für ein Spiel und eine Art einreihen. Bereits Eingereihte werden ignoriert. */
    public static function enqueue(int $gameId, string $kind): int
    {
        $stmt = db()->prepare("INSERT OR IGNORE INTO notifications (game_id, email, kind, created_at)
                               SELECT DISTINCT game_id, lower(trim(email)), ?, ? FROM votes
                               WHERE game_id = ? AND email IS NOT NULL AND trim(email) <> ''");
        $stmt->execute([$kind, now(), $gameId]);
        return $stmt->rowCount();
    }

    /** Offene Mails verschicken. Gibt [gesendet, fehlgeschlagen] zurück. */
    public static function processQueue(int $limit = 50): array
    {
        if (!Mailer::isConfigured()) {
            return [0, 0];
        }
        $rows = db()->query("SELECT n.*, g.name, g.appid FROM notifications n JOIN games g ON g.id = n.game_id
                             WHERE n.sent_at IS NULL AND n.attempts < 5 ORDER BY n.id LIMIT " . (int)$limit)->fetchAll();
        $sent = $failed = 0;
        foreach ($rows as $n) {
            [$subject, $text, $html] = self::compose($n);
            try {
                Mailer::send($n['email'], $subject, $text, $html);
                db()->prepare('UPDATE notifications SET sent_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?')
                    ->execute([now(), $n['id']]);
                $sent++;
            } catch (Throwable $e) {
                db()->prepare('UPDATE notifications SET attempts = attempts + 1, last_error = ? WHERE id = ?')
                    ->execute([mb_substr($e->getMessage(), 0, 500), $n['id']]);
                log_msg('Mail an ' . $n['email'] . ' fehlgeschlagen: ' . $e->getMessage());
                $failed++;
            }
        }
        return [$sent, $failed];
    }

    /** @return array{0:string,1:string,2:string} */
    private static function compose(array $n): array
    {
        $site = (string)cfg('site_name');
        $name = (string)$n['name'];
        $steamUrl = 'https://store.steampowered.com/app/' . (int)$n['appid'];
        if ($n['kind'] === 'released') {
            $subject = "{$name} ist auf Steam erschienen";
            $line = "Das von dir gewünschte Spiel „{$name}“ ist jetzt offiziell auf Steam erschienen.";
            $link = $steamUrl;
            $linkText = 'Zur Steam-Seite';
        } else {
            $subject = "{$name} ist jetzt auf GameVault verfügbar!";
            $line = "Gute Nachrichten: Dein Wunsch „{$name}“ liegt jetzt auf GameVault für dich bereit.";
            $link = GameVault::baseUrl() ?: $steamUrl;
            $linkText = 'Zu GameVault';
        }
        $wl = rtrim((string)cfg('base_url', ''), '/');
        $text = "Hallo,\n\n{$line}\n\n{$linkText}: {$link}\n\nViele Grüße\n{$site}" . ($wl ? "\n{$wl}" : '');
        $cover = steam_header((int)$n['appid']);
        $html = '<div style="font-family:Arial,sans-serif;background:#1b2838;color:#c7d5e0;padding:24px">'
            . '<div style="max-width:480px;margin:auto;background:#16202d;border-radius:8px;overflow:hidden">'
            . '<img src="' . h($cover) . '" alt="" style="width:100%;display:block">'
            . '<div style="padding:20px"><h2 style="color:#fff;margin:0 0 12px">' . h($subject) . '</h2>'
            . '<p>' . h($line) . '</p>'
            . '<p><a href="' . h($link) . '" style="display:inline-block;background:#66c0f4;color:#0b141e;padding:10px 18px;border-radius:4px;text-decoration:none;font-weight:bold">' . h($linkText) . '</a></p>'
            . '<p style="color:#8f98a0;font-size:12px">' . h($site) . '</p></div></div></div>';
        return [$subject, $text, $html];
    }
}

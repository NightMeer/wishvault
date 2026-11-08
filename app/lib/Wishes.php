<?php
declare(strict_types=1);

/** Logik der Wunschliste: Wünschen, Upvoten, Status setzen, GameVault-Abgleich. */
final class Wishes
{
    public static function byAppId(int $appid): ?array
    {
        $stmt = db()->prepare('SELECT * FROM games WHERE appid = ?');
        $stmt->execute([$appid]);
        return $stmt->fetch() ?: null;
    }

    public static function byId(int $id): ?array
    {
        $stmt = db()->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Passendes GameVault-Spiel suchen: zuerst über Steam-AppID, dann über normalisierten Titel. */
    public static function findGvMatch(int $appid, string $name): ?array
    {
        $stmt = db()->prepare('SELECT *, \'appid\' AS match_type FROM gv_games WHERE removed = 0 AND appid = ? LIMIT 1');
        $stmt->execute([$appid]);
        if ($row = $stmt->fetch()) {
            return $row;
        }
        $norm = normalize_title($name);
        if ($norm === '') {
            return null;
        }
        // Titel-Fallback nur, wenn das GameVault-Spiel keine (andere) Steam-AppID hat
        $stmt = db()->prepare('SELECT *, \'title\' AS match_type FROM gv_games
                               WHERE removed = 0 AND (appid IS NULL OR appid = ?) AND (norm_title = ? OR norm_meta_title = ?) LIMIT 1');
        $stmt->execute([$appid, $norm, $norm]);
        return $stmt->fetch() ?: null;
    }

    /** Suchergebnisse um Status (bereits gewünscht / in GameVault) ergänzen */
    public static function annotate(array $results, string $voter): array
    {
        foreach ($results as &$r) {
            $wish = self::byAppId($r['appid']);
            $gv = self::findGvMatch($r['appid'], $r['name']);
            $r['in_gamevault'] = $gv !== null || ($wish && $wish['status'] === 'available');
            $r['gv_url'] = GameVault::gameUrl($gv ? (int)$gv['id'] : (!empty($wish['gv_game_id']) ? (int)$wish['gv_game_id'] : null));
            $r['wish'] = $wish ? self::publicRow($wish, $voter) : null;
        }
        return $results;
    }

    /**
     * Spiel wünschen: legt neuen Eintrag an oder zählt bestehenden hoch.
     * @return array{result:string, message:string, game?:array}
     */
    public static function wish(int $appid, string $voter, ?string $email): array
    {
        $existing = self::byAppId($appid);
        if ($existing) {
            return self::upvote((int)$existing['id'], $voter, $email);
        }
        $details = Steam::appDetails($appid);
        if (!$details) {
            return ['result' => 'error', 'message' => 'Spiel wurde auf Steam nicht gefunden.'];
        }
        if (self::findGvMatch($appid, $details['name'])) {
            return ['result' => 'in_gamevault', 'message' => 'Dieses Spiel ist bereits auf GameVault verfügbar!'];
        }
        $now = now();
        db()->prepare('INSERT INTO games (appid, name, coming_soon, release_date, header_image, status, votes, steam_checked_at, created_at, updated_at, last_vote_at)
                       VALUES (?, ?, ?, ?, ?, \'new\', 0, ?, ?, ?, ?)')
            ->execute([$appid, $details['name'], (int)$details['coming_soon'], $details['release_date'], $details['header_image'], $now, $now, $now, $now]);
        $id = (int)db()->lastInsertId();
        self::addVote($id, $voter, $email);
        return ['result' => 'created', 'message' => 'Wunsch eingetragen!', 'game' => self::publicRow(self::byId($id), $voter)];
    }

    /** @return array{result:string, message:string, game?:array} */
    public static function upvote(int $gameId, string $voter, ?string $email): array
    {
        $game = self::byId($gameId);
        if (!$game) {
            return ['result' => 'error', 'message' => 'Spiel nicht gefunden.'];
        }
        if ($game['status'] === 'available') {
            return ['result' => 'in_gamevault', 'message' => 'Dieses Spiel ist bereits auf GameVault verfügbar!', 'game' => self::publicRow($game, $voter)];
        }
        if ($game['status'] === 'rejected') {
            return ['result' => 'rejected', 'message' => 'Dieser Wunsch wurde abgelehnt' . ($game['reject_reason'] ? ': ' . $game['reject_reason'] : '.'), 'game' => self::publicRow($game, $voter)];
        }
        $added = self::addVote($gameId, $voter, $email);
        $game = self::byId($gameId);
        return $added
            ? ['result' => 'upvoted', 'message' => 'Das Spiel war schon gewünscht – deine Stimme wurde gezählt (+1)!', 'game' => self::publicRow($game, $voter)]
            : ['result' => 'already_voted', 'message' => 'Du hast für dieses Spiel bereits abgestimmt.' . ($email ? ' E-Mail wurde aktualisiert.' : ''), 'game' => self::publicRow($game, $voter)];
    }

    /**
     * Eigene Stimme zurückziehen. Hat ein noch unbearbeiteter Wunsch danach keine Stimmen mehr, wird er entfernt.
     * @return array{result:string, message:string, game?:array}
     */
    public static function unvote(int $gameId, string $voter): array
    {
        $game = self::byId($gameId);
        if (!$game) {
            return ['result' => 'error', 'message' => 'Spiel nicht gefunden.'];
        }
        $stmt = db()->prepare('DELETE FROM votes WHERE game_id = ? AND voter = ?');
        $stmt->execute([$gameId, $voter]);
        if ($stmt->rowCount() === 0) {
            return ['result' => 'error', 'message' => 'Du hast für dieses Spiel nicht abgestimmt.'];
        }
        db()->prepare('UPDATE games SET votes = MAX(votes - 1, 0) WHERE id = ?')->execute([$gameId]);
        $game = self::byId($gameId);
        if ((int)$game['votes'] === 0 && $game['status'] === 'new') {
            self::delete($gameId);
            return ['result' => 'removed', 'message' => 'Stimme zurückgezogen – der Wunsch hatte keine weiteren Stimmen und wurde entfernt.'];
        }
        return ['result' => 'unvoted', 'message' => 'Deine Stimme wurde zurückgezogen.', 'game' => self::publicRow($game, $voter)];
    }

    /** Stimme speichern; true wenn neu. Bei bereits vorhandener Stimme wird nur die E-Mail ergänzt. */
    private static function addVote(int $gameId, string $voter, ?string $email): bool
    {
        $pdo = db();
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO votes (game_id, voter, email, created_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$gameId, $voter, $email, now()]);
        if ($stmt->rowCount() > 0) {
            $pdo->prepare('UPDATE games SET votes = votes + 1, last_vote_at = ? WHERE id = ?')->execute([now(), $gameId]);
            return true;
        }
        if ($email) {
            $pdo->prepare('UPDATE votes SET email = ? WHERE game_id = ? AND voter = ?')->execute([$email, $gameId, $voter]);
        }
        return false;
    }

    /** Status ändern; bei "Verfügbar" optional Benachrichtigungen einreihen. Ablehnungsgrund nur bei "Abgelehnt". */
    public static function setStatus(int $gameId, string $status, bool $notify = true, ?string $reason = null): void
    {
        if (!isset(STATUS_LABELS[$status])) {
            throw new InvalidArgumentException('Ungültiger Status');
        }
        $reason = $status === 'rejected' ? (trim((string)$reason) !== '' ? mb_substr(trim((string)$reason), 0, 300) : null) : null;
        db()->prepare('UPDATE games SET status = ?, reject_reason = ?, updated_at = ? WHERE id = ?')->execute([$status, $reason, now(), $gameId]);
        if ($status === 'available' && $notify) {
            Notifier::enqueue($gameId, 'available');
        }
    }

    public static function delete(int $gameId): void
    {
        db()->prepare('DELETE FROM games WHERE id = ?')->execute([$gameId]);
    }

    /** Öffentliche Liste */
    public static function list(string $sort, string $voter, string $filter = 'open'): array
    {
        $order = $sort === 'new' ? 'created_at DESC' : 'votes DESC, last_vote_at DESC';
        $where = match ($filter) {
            'all'       => '1=1',
            'available' => "status = 'available'",
            'rejected'  => "status = 'rejected'",
            default     => "status IN ('new','queued','paused')",
        };
        $rows = db()->query("SELECT * FROM games WHERE {$where} ORDER BY {$order}")->fetchAll();
        return array_map(fn($g) => self::publicRow($g, $voter), $rows);
    }

    public static function publicRow(array $g, string $voter): array
    {
        $stmt = db()->prepare('SELECT 1 FROM votes WHERE game_id = ? AND voter = ?');
        $stmt->execute([$g['id'], $voter]);
        $voted = (bool)$stmt->fetchColumn();
        $gvCover = null;
        if (!empty($g['gv_game_id'])) {
            $c = db()->prepare('SELECT cover_media_id FROM gv_games WHERE id = ?');
            $c->execute([$g['gv_game_id']]);
            $gvCover = gv_image(($v = $c->fetchColumn()) ? (int)$v : null);
        }
        return [
            'id'           => (int)$g['id'],
            'appid'        => (int)$g['appid'],
            'name'         => $g['name'],
            'status'       => $g['status'],
            'status_label' => STATUS_LABELS[$g['status']] ?? $g['status'],
            'votes'        => (int)$g['votes'],
            'coming_soon'  => (bool)$g['coming_soon'],
            'release_date' => $g['release_date'],
            'cover'        => $gvCover ?? steam_cover((int)$g['appid']),
            'header'       => $g['header_image'] ?: steam_header((int)$g['appid']),
            'created_at'   => $g['created_at'],
            'voted'        => $voted,
            'reject_reason' => $g['status'] === 'rejected' ? ($g['reject_reason'] ?? null) : null,
            'steam_url'    => 'https://store.steampowered.com/app/' . (int)$g['appid'],
            'gv_url'       => $g['status'] === 'available' ? GameVault::gameUrl($g['gv_game_id'] ? (int)$g['gv_game_id'] : null) : null,
        ];
    }
}

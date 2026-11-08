<?php
declare(strict_types=1);

/** Datenbankschema mit einfachen, versionierten Migrationen (PRAGMA user_version). */
final class Schema
{
    private const MIGRATIONS = [
        1 => [
            "CREATE TABLE settings (
                key   TEXT PRIMARY KEY,
                value TEXT
            )",
            "CREATE TABLE admins (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL,
                created_at    TEXT NOT NULL
            )",
            // Wunschliste (ein Eintrag pro Steam-App)
            "CREATE TABLE games (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                appid            INTEGER NOT NULL UNIQUE,
                name             TEXT NOT NULL,
                coming_soon      INTEGER NOT NULL DEFAULT 0,
                release_date     TEXT,
                header_image     TEXT,
                status           TEXT NOT NULL DEFAULT 'new',
                votes            INTEGER NOT NULL DEFAULT 0,
                gv_game_id       INTEGER,
                gv_match         TEXT,
                admin_note       TEXT,
                steam_checked_at TEXT,
                created_at       TEXT NOT NULL,
                updated_at       TEXT NOT NULL,
                last_vote_at     TEXT NOT NULL
            )",
            "CREATE INDEX idx_games_status ON games(status)",
            // Ein Vote pro Browser (voter-Token) und Spiel; E-Mail optional
            "CREATE TABLE votes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
                voter      TEXT NOT NULL,
                email      TEXT,
                created_at TEXT NOT NULL,
                UNIQUE(game_id, voter)
            )",
            // Mail-Warteschlange + Schutz gegen Mehrfachversand (UNIQUE je Spiel/Mail/Art)
            "CREATE TABLE notifications (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id    INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
                email      TEXT NOT NULL COLLATE NOCASE,
                kind       TEXT NOT NULL,
                created_at TEXT NOT NULL,
                sent_at    TEXT,
                attempts   INTEGER NOT NULL DEFAULT 0,
                last_error TEXT,
                UNIQUE(game_id, email, kind)
            )",
            // Gespiegelte GameVault-Bibliothek
            "CREATE TABLE gv_games (
                id                INTEGER PRIMARY KEY,
                title             TEXT NOT NULL,
                meta_title        TEXT,
                norm_title        TEXT,
                norm_meta_title   TEXT,
                appid             INTEGER,
                appid_override    INTEGER,
                version           TEXT,
                file_path         TEXT,
                size              TEXT,
                cover_url         TEXT,
                fingerprint       TEXT,
                gv_created_at     TEXT,
                file_changed_at   TEXT,
                removed           INTEGER NOT NULL DEFAULT 0,
                seen_at           TEXT,
                steam_buildid     TEXT,
                steam_timeupdated INTEGER,
                steam_checked_at  TEXT,
                ack_timeupdated   INTEGER
            )",
            "CREATE INDEX idx_gv_appid ON gv_games(appid)",
            "CREATE INDEX idx_gv_norm ON gv_games(norm_title)",
            "CREATE INDEX idx_gv_norm_meta ON gv_games(norm_meta_title)",
            // GSE-Spieler; SteamIDs werden nie wiederverwendet
            "CREATE TABLE gse_users (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                username   TEXT COLLATE NOCASE,
                steam_id   TEXT NOT NULL UNIQUE,
                gv_user_id INTEGER UNIQUE,
                source     TEXT NOT NULL DEFAULT 'manual',
                is_deleted INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL
            )",
            "CREATE UNIQUE INDEX idx_gse_username_active ON gse_users(username) WHERE is_deleted = 0",
            "CREATE TABLE cache (
                key        TEXT PRIMARY KEY,
                value      TEXT NOT NULL,
                expires_at INTEGER NOT NULL
            )",
        ],
        2 => [
            "ALTER TABLE gv_games ADD COLUMN cover_media_id INTEGER",
            "ALTER TABLE gv_games ADD COLUMN background_media_id INTEGER",
        ],
        3 => [
            "ALTER TABLE games ADD COLUMN reject_reason TEXT",
        ],
    ];

    public static function migrate(PDO $pdo): void
    {
        $current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        foreach (self::MIGRATIONS as $version => $statements) {
            if ($version <= $current) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                foreach ($statements as $sql) {
                    $pdo->exec($sql);
                }
                $pdo->exec('PRAGMA user_version = ' . (int)$version);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }
}

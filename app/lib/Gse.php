<?php
declare(strict_types=1);

/**
 * Goldberg-Emulator (GSE) Spielerverwaltung + Generierung der Setup-Datei.
 * is_deleted: 0 = aktiv, 1 = durch GameVault-Sync deaktiviert (wird bei Reaktivierung wiederhergestellt),
 *             2 = vom Admin entfernt. SteamIDs bleiben in jedem Fall reserviert.
 */
final class Gse
{
    public static function activeUsers(): array
    {
        return db()->query('SELECT * FROM gse_users WHERE is_deleted = 0 ORDER BY CAST(steam_id AS INTEGER)')->fetchAll();
    }

    public static function findActiveByName(string $name): ?array
    {
        $stmt = db()->prepare('SELECT * FROM gse_users WHERE is_deleted = 0 AND username = ?');
        $stmt->execute([trim($name)]);
        return $stmt->fetch() ?: null;
    }

    public static function nextSteamId(): string
    {
        $max = db()->query('SELECT MAX(CAST(steam_id AS INTEGER)) FROM gse_users')->fetchColumn();
        $start = (int)cfg('gse.steamid_start', '76561198000000001');
        return (string)($max ? max((int)$max + 1, $start) : $start);
    }

    public static function validName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 32) {
            return 'Der Name muss 1–32 Zeichen lang sein.';
        }
        if (preg_match('/[\x00-\x1F\x7F=\[\]]/u', $name)) {
            return 'Der Name enthält ungültige Zeichen.';
        }
        return null;
    }

    public static function create(string $name, ?int $gvUserId = null): array
    {
        if ($err = self::validName($name)) {
            throw new InvalidArgumentException($err);
        }
        if (self::findActiveByName($name)) {
            throw new InvalidArgumentException('Ein aktiver Spieler mit diesem Namen existiert bereits.');
        }
        $steamId = self::nextSteamId();
        db()->prepare('INSERT INTO gse_users (username, steam_id, gv_user_id, source, is_deleted, created_at) VALUES (?, ?, ?, ?, 0, ?)')
            ->execute([trim($name), $steamId, $gvUserId, $gvUserId ? 'gamevault' : 'manual', now()]);
        return ['username' => trim($name), 'steam_id' => $steamId];
    }

    public static function rename(int $id, string $name): void
    {
        if ($err = self::validName($name)) {
            throw new InvalidArgumentException($err);
        }
        $other = self::findActiveByName($name);
        if ($other && (int)$other['id'] !== $id) {
            throw new InvalidArgumentException('Dieser Name ist bereits vergeben.');
        }
        db()->prepare('UPDATE gse_users SET username = ? WHERE id = ?')->execute([trim($name), $id]);
    }

    public static function remove(int $id): void
    {
        db()->prepare('UPDATE gse_users SET is_deleted = 2 WHERE id = ?')->execute([$id]);
    }

    /** GameVault-Nutzer übernehmen. Gibt Anzahl neu angelegter Spieler zurück. */
    public static function syncFromGameVault(array $gvUsers): int
    {
        $created = 0;
        $activeIds = [];
        foreach ($gvUsers as $u) {
            $gvId = (int)($u['id'] ?? 0);
            $name = trim((string)($u['username'] ?? ''));
            if (!$gvId || $name === '' || empty($u['activated']) || !empty($u['deleted_at'])) {
                continue;
            }
            $activeIds[] = $gvId;
            $stmt = db()->prepare('SELECT * FROM gse_users WHERE gv_user_id = ?');
            $stmt->execute([$gvId]);
            $row = $stmt->fetch();
            try {
                if ($row) {
                    if ((int)$row['is_deleted'] === 2) {
                        continue; // vom Admin bewusst entfernt
                    }
                    if ((int)$row['is_deleted'] === 1 || $row['username'] !== $name) {
                        db()->prepare('UPDATE gse_users SET username = ?, is_deleted = 0 WHERE id = ?')->execute([$name, $row['id']]);
                    }
                    continue;
                }
                // Manuell angelegten Spieler mit gleichem Namen verknüpfen
                $manual = self::findActiveByName($name);
                if ($manual && $manual['gv_user_id'] === null) {
                    db()->prepare("UPDATE gse_users SET gv_user_id = ?, source = 'gamevault' WHERE id = ?")->execute([$gvId, $manual['id']]);
                    continue;
                }
                if (!$manual && !self::validName($name)) {
                    self::create($name, $gvId);
                    $created++;
                }
            } catch (Throwable $e) {
                log_msg("GSE-Sync für GameVault-Nutzer {$name}: " . $e->getMessage());
            }
        }
        // Nicht mehr aktive GameVault-Nutzer deaktivieren (SteamID bleibt reserviert)
        $sql = 'UPDATE gse_users SET is_deleted = 1 WHERE gv_user_id IS NOT NULL AND is_deleted = 0';
        if ($activeIds) {
            $sql .= ' AND gv_user_id NOT IN (' . implode(',', array_map('intval', $activeIds)) . ')';
        }
        db()->exec($sql);
        return $created;
    }

    /** INI-Werte für einen Spieler */
    public static function iniSections(array $user, string $language): array
    {
        $langs = cfg('gse.languages', []);
        $country = $langs[$language][1] ?? (string)cfg('steam.country', 'DE');
        $sections = [
            'user::general' => [
                'account_name'    => $user['username'],
                'account_steamid' => $user['steam_id'],
                'language'        => $language,
                'ip_country'      => $country,
            ],
        ];
        $savePath = (string)cfg('gse.local_save_path', '');
        if ($savePath !== '') {
            $sections['user::saves'] = ['local_save_path' => $savePath];
        }
        return $sections;
    }

    /** Komplette configs.user.ini (zum manuellen Ablegen) */
    public static function buildIni(array $user, string $language): string
    {
        $out = [];
        foreach (self::iniSections($user, $language) as $section => $values) {
            $out[] = "[{$section}]";
            foreach ($values as $k => $v) {
                $out[] = "{$k}={$v}";
            }
            $out[] = '';
        }
        return implode("\r\n", $out);
    }

    /**
     * Setup-.bat als lesbare Batch/PowerShell-Hybriddatei:
     * Der Batch-Teil (zwischen <# und #>) startet PowerShell, das die eigene Datei als Skript ausführt.
     * Für PowerShell ist der Batch-Teil ein Blockkommentar. Werte stehen als PowerShell-Strings in
     * einfachen Anführungszeichen – Sonderzeichen im Namen (& % ^ ! " usw.) sind daher unproblematisch.
     * Bestehende Einstellungen in der configs.user.ini bleiben erhalten.
     */
    public static function buildBat(array $user, string $language): string
    {
        $q = fn(string $s) => "'" . str_replace("'", "''", $s) . "'";
        $sections = self::iniSections($user, $language);

        $werte = [];
        foreach ($sections as $section => $values) {
            $pad = max(array_map('strlen', array_keys($values)));
            $werte[] = '    ' . $q($section) . ' = [ordered]@{';
            foreach ($values as $k => $v) {
                $werte[] = '        ' . str_pad($q($k), $pad + 2) . ' = ' . $q((string)$v);
            }
            $werte[] = '    }';
        }
        $werteBlock = implode("\n", $werte);
        $label = $user['username'] . ' (' . $user['steam_id'] . ')';
        $labelPs = $q($label);
        $site = (string)cfg('site_name');

        $script = <<<BAT
<# :
@echo off
chcp 65001 >nul
title GSE LAN Config
set "GSE_BAT=%~f0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-Expression ([IO.File]::ReadAllText(\$env:GSE_BAT, [Text.Encoding]::UTF8))"
if errorlevel 1 (
    echo.
    echo FEHLER: Die Config konnte nicht geschrieben werden.
    pause
    exit /b 1
)
echo.
echo Fertig! Druecke eine beliebige Taste zum Schliessen.
pause >nul
exit /b
#>

# ==========================================================================
#  Goldberg Emulator (GSE) - Spieler-Config
#  Erstellt von: {$site}
#
#  Schreibt bzw. aktualisiert:  %APPDATA%\\GSE Saves\\settings\\configs.user.ini
#  Vorhandene Einstellungen in der Datei bleiben erhalten,
#  nur die folgenden Werte werden gesetzt:
# ==========================================================================

\$Werte = [ordered]@{
{$werteBlock}
}

\$ErrorActionPreference = 'Stop'
\$Ordner = Join-Path \$env:APPDATA 'GSE Saves\\settings'
\$Datei  = Join-Path \$Ordner 'configs.user.ini'
New-Item -ItemType Directory -Force -Path \$Ordner | Out-Null

# Bestehende Datei einlesen
\$Alt = @()
if (Test-Path -LiteralPath \$Datei) {
    \$Alt = @(Get-Content -LiteralPath \$Datei -Encoding UTF8)
}

\$Neu       = New-Object Collections.Generic.List[string]
\$Sektion   = \$null
\$Erledigt  = @{}

# Fehlende Schlüssel am Ende der aktuellen Sektion ergänzen
function Sektion-Abschliessen {
    if (\$Sektion -and \$Werte.Contains(\$Sektion)) {
        \$pos = \$Neu.Count
        while (\$pos -gt 0 -and \$Neu[\$pos - 1].Trim() -eq '') { \$pos-- }
        foreach (\$key in \$Werte[\$Sektion].Keys) {
            if (-not \$Erledigt["\$Sektion|\$key"]) {
                \$Neu.Insert(\$pos, "\$key=" + \$Werte[\$Sektion][\$key])
                \$pos++
            }
        }
    }
    if (\$Sektion) { \$Erledigt["#\$Sektion"] = \$true }
}

foreach (\$zeile in \$Alt) {
    if (\$zeile -match '^\s*\[(.+?)\]\s*$') {
        Sektion-Abschliessen
        \$Sektion = \$matches[1]
        \$Neu.Add(\$zeile)
        continue
    }
    if (\$Sektion -and \$Werte.Contains(\$Sektion) -and \$zeile -match '^\s*([^#;=\s][^=]*?)\s*=') {
        \$key = \$matches[1]
        if (\$Werte[\$Sektion].Contains(\$key)) {
            \$Neu.Add("\$key=" + \$Werte[\$Sektion][\$key])
            \$Erledigt["\$Sektion|\$key"] = \$true
            continue
        }
    }
    \$Neu.Add(\$zeile)
}
Sektion-Abschliessen

# Sektionen, die es noch nicht gab, anhängen
foreach (\$name in @(\$Werte.Keys)) {
    if (-not \$Erledigt["#\$name"]) {
        if (\$Neu.Count -and \$Neu[\$Neu.Count - 1].Trim() -ne '') { \$Neu.Add('') }
        \$Neu.Add("[\$name]")
        foreach (\$key in \$Werte[\$name].Keys) { \$Neu.Add("\$key=" + \$Werte[\$name][\$key]) }
    }
}

# UTF-8 ohne BOM speichern
[IO.File]::WriteAllLines(\$Datei, \$Neu, (New-Object Text.UTF8Encoding \$false))

Write-Host ''
Write-Host ('Config fuer ' + {$labelPs} + ' gesetzt:') -ForegroundColor Green
Write-Host \$Datei

BAT;
        // Windows-Zeilenenden (wichtig für cmd)
        return str_replace(["\r\n", "\n"], ["\n", "\r\n"], $script);
    }
}

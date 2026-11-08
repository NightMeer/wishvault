# WishVault

Spielwünsche & LAN-Konfigurator für einen GameVault-Server (PHP 8.5 + SQLite, ohne Framework).

- **Wunschliste:** Steam-Spiele suchen und wünschen, Upvotes geben oder zurückziehen, optional per Mail benachrichtigt werden
- **Admin:** Status-Workflow (mit Ablehnungsgrund), GameVault-Abgleich, Update-Hinweise (Build-ID bzw. Datum), Steam-Tags zum Kopieren
- **LAN-Konfigurator:** feste SteamIDs für den Goldberg-Emulator, Setup als lesbare `.bat`

## Start

```sh
cp docker-compose.example.yml docker-compose.yml   # Werte eintragen (Passwörter!)
docker compose up -d                               # nutzt ghcr.io/nightmeer/wishvault
# oder selbst bauen: in docker-compose.yml "build: ." aktivieren, dann
docker compose up -d --build
```

Die echte `docker-compose.yml` ist per `.gitignore` vom Repo ausgeschlossen.

Das Image enthält die komplette Anwendung. Es braucht keine Host-Ordner, nur das Docker-Volume
`wishvault-data` für Datenbank, Bilder-Cache und Logs.

## Konfiguration

Alles wird über die Umgebungsvariablen in `docker-compose.yml` gesetzt, die Datei ist dort kommentiert.
Nach Änderungen: `docker compose up -d`. Ein neuer Build ist dafür nicht nötig.

| Bereich   | Variablen |
|-----------|-----------|
| Allgemein | `SITE_NAME`, `SITE_TAGLINE`, `SITE_FAVICON`, `SITE_LOGO`, `BASE_URL`, `TZ`, `CRON_SCHEDULE` |
| Admin     | `ADMIN_USER`, `ADMIN_PASS` (Superadmin; weitere Admins im Web anlegbar) |
| GameVault | `GAMEVAULT_URL`, `GAMEVAULT_USERNAME`, `GAMEVAULT_PASSWORD`, `GAMEVAULT_AUTO_AVAILABLE`, `GAMEVAULT_NOTIFY_ON_AUTO_AVAILABLE`, `GAMEVAULT_SYNC_USERS` |
| Steam     | `STEAM_COUNTRY`, `STEAM_LANGUAGE`, `STEAM_TAGS_LANGUAGE`, `STEAM_RECHECK_HOURS` |
| E-Mail    | `SMTP_HOST`, `SMTP_PORT`, `SMTP_ENCRYPTION`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` |
| GSE       | `GSE_STEAMID_START`, `GSE_LOCAL_SAVE_PATH`, `GSE_DEFAULT_LANGUAGE`, `GSE_LANGUAGES` |

`SITE_FAVICON` / `SITE_LOGO` akzeptieren eine URL (svg, png, ico …). Bleiben sie leer, wird das eingebaute WishVault-Icon verwendet.

## Hintergrundjobs

Cron läuft im Container nach `CRON_SCHEDULE` und erledigt GameVault-Sync, Steam-Release-Check, Versionsabgleich und Mailversand.
Manuell ausführen:

```sh
docker compose exec -u www-data wishvault php /var/www/app/cron.php          # normal
docker compose exec -u www-data wishvault php /var/www/app/cron.php --force  # alle Steam-Daten neu abfragen
```

## Struktur

| Pfad       | Inhalt                                          |
|------------|-------------------------------------------------|
| `app/`     | Logik, Konfiguration (liest Umgebungsvariablen) |
| `public/`  | Webroot (Seiten, Admin, Assets, Icon)           |
| `docker/`  | Entrypoint, Apache-Ergänzung                    |

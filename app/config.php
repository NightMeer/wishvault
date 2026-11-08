<?php
/**
 * Konfiguration – wird ausschließlich über Umgebungsvariablen gesetzt (docker-compose.yml).
 * Die Werte hier sind nur Standardwerte, falls eine Variable fehlt.
 */
$env = static function (string $key, ?string $default = null): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};
$bool = static function (string $key, bool $default) use ($env): bool {
    $v = $env($key);
    return $v === null ? $default : in_array(strtolower($v), ['1', 'true', 'yes', 'on', 'ja'], true);
};

// GSE-Sprachen: "schluessel:Anzeigename:LAND,..." z. B. "german:Deutsch:DE,english:Englisch:US"
$languages = [];
foreach (explode(',', (string)$env('GSE_LANGUAGES', 'german:Deutsch:DE,english:Englisch:US,french:Französisch:FR,italian:Italienisch:IT,polish:Polnisch:PL,russian:Russisch:RU,spanish:Spanisch:ES')) as $entry) {
    $parts = array_map('trim', explode(':', $entry));
    if (count($parts) === 3 && $parts[0] !== '') {
        $languages[$parts[0]] = [$parts[1], strtoupper($parts[2])];
    }
}

$siteName = (string)$env('SITE_NAME', 'WishVault');
$favicon  = (string)$env('SITE_FAVICON', '/assets/wishvault.svg');

return [
    'site_name'    => $siteName,
    'site_tagline' => (string)$env('SITE_TAGLINE', 'Spielwünsche für den GameVault-Server'),
    'favicon'      => $favicon,
    'logo'         => (string)$env('SITE_LOGO', $favicon),
    'base_url'     => rtrim((string)$env('BASE_URL', ''), '/'),
    'timezone'     => (string)$env('TZ', 'Europe/Berlin'),
    'data_dir'     => (string)$env('DATA_DIR', '/var/www/data'),

    'admin' => [
        'user' => (string)$env('ADMIN_USER', 'admin'),
        'pass' => (string)$env('ADMIN_PASS', ''),
    ],

    'gamevault' => [
        'url'                      => rtrim((string)$env('GAMEVAULT_URL', ''), '/'),
        'username'                 => (string)$env('GAMEVAULT_USERNAME', ''),
        'password'                 => (string)$env('GAMEVAULT_PASSWORD', ''),
        'auto_available'           => $bool('GAMEVAULT_AUTO_AVAILABLE', true),
        'notify_on_auto_available' => $bool('GAMEVAULT_NOTIFY_ON_AUTO_AVAILABLE', false),
        'sync_users'               => $bool('GAMEVAULT_SYNC_USERS', true),
    ],

    'steam' => [
        'country'       => (string)$env('STEAM_COUNTRY', 'DE'),
        'language'      => (string)$env('STEAM_LANGUAGE', 'german'),
        'tags_language' => (string)$env('STEAM_TAGS_LANGUAGE', 'english'),
        'recheck_hours' => (int)$env('STEAM_RECHECK_HOURS', '6'),
    ],

    'smtp' => [
        'host'       => (string)$env('SMTP_HOST', ''),
        'port'       => (int)$env('SMTP_PORT', '587'),
        'encryption' => strtolower((string)$env('SMTP_ENCRYPTION', 'tls')),
        'username'   => (string)$env('SMTP_USERNAME', ''),
        'password'   => (string)$env('SMTP_PASSWORD', ''),
        'from_email' => (string)$env('SMTP_FROM_EMAIL', ''),
        'from_name'  => (string)$env('SMTP_FROM_NAME', $siteName),
    ],

    'gse' => [
        'steamid_start'   => (string)$env('GSE_STEAMID_START', '76561198000000001'),
        'local_save_path' => (string)$env('GSE_LOCAL_SAVE_PATH', 'savedata'),
        'languages'       => $languages,
        'default_language' => (string)$env('GSE_DEFAULT_LANGUAGE', 'german'),
    ],
];

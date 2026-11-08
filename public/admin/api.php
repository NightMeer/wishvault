<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';
if (!Auth::user()) {
    json_out(['error' => 'Nicht angemeldet'], 401);
}

switch ($_GET['action'] ?? '') {
    case 'tags':
        $appid = (int)($_GET['appid'] ?? 0);
        if ($appid <= 0) {
            json_out(['error' => 'AppID fehlt'], 422);
        }
        try {
            json_out(['appid' => $appid, 'tags' => Steam::tags($appid)]);
        } catch (Throwable $e) {
            json_out(['error' => 'Steam nicht erreichbar: ' . $e->getMessage()], 502);
        }
    default:
        json_out(['error' => 'Unbekannte Aktion'], 404);
}

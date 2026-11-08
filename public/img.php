<?php
declare(strict_types=1);
/** Proxy + Cache für GameVault-Bilder (Cover/Hintergründe), da die GameVault-API eine Anmeldung erfordert. */
require '/var/www/app/bootstrap.php';

$id = (int)($_GET['m'] ?? 0);
$stmt = db()->prepare('SELECT 1 FROM gv_games WHERE cover_media_id = ? OR background_media_id = ? LIMIT 1');
$stmt->execute([$id, $id]);
if ($id <= 0 || !$stmt->fetchColumn()) {
    http_response_code(404);
    exit;
}

$dir = cfg('data_dir') . '/media';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$file = $dir . '/' . $id;
if (!is_file($file) || filesize($file) === 0) {
    try {
        $data = GameVault::media($id);
    } catch (Throwable $e) {
        log_msg('Bild ' . $id . ': ' . $e->getMessage());
        $data = null;
    }
    if ($data === null) {
        http_response_code(404);
        exit;
    }
    file_put_contents($file . '.tmp', $data);
    rename($file . '.tmp', $file);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
if (!str_starts_with($mime, 'image/')) {
    http_response_code(404);
    exit;
}
$etag = '"' . $id . '-' . filesize($file) . '"';
header('Cache-Control: public, max-age=604800');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
readfile($file);

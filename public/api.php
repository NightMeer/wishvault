<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';

// Anonymer Browser-Token für "ein Vote pro Browser"
$voter = $_COOKIE['wl_voter'] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $voter)) {
    $voter = bin2hex(random_bytes(16));
    setcookie('wl_voter', $voter, [
        'expires' => time() + 86400 * 730, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'search':
            $q = trim((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) {
                json_out(['results' => []]);
            }
            $results = array_slice(Steam::search($q), 0, 10);
            json_out(['results' => Wishes::annotate($results, $voter)]);

        case 'list':
            $sort = ($_GET['sort'] ?? '') === 'new' ? 'new' : 'votes';
            $filter = (string)($_GET['filter'] ?? 'open');
            json_out(['games' => Wishes::list($sort, $voter, $filter)]);

        case 'unvote':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_out(['error' => 'POST erforderlich'], 405);
            }
            csrf_check(true);
            $res = Wishes::unvote((int)($_POST['id'] ?? 0), $voter);
            json_out($res, $res['result'] === 'error' ? 422 : 200);

        case 'wish':
        case 'upvote':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_out(['error' => 'POST erforderlich'], 405);
            }
            csrf_check(true);
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                json_out(['result' => 'error', 'message' => 'Die E-Mail-Adresse ist ungültig.'], 422);
            }
            $email = $email !== '' ? mb_strtolower($email) : null;
            $res = $action === 'wish'
                ? Wishes::wish((int)($_POST['appid'] ?? 0), $voter, $email)
                : Wishes::upvote((int)($_POST['id'] ?? 0), $voter, $email);
            json_out($res, $res['result'] === 'error' ? 422 : 200);

        default:
            json_out(['error' => 'Unbekannte Aktion'], 404);
    }
} catch (Throwable $e) {
    log_msg('API ' . $action . ': ' . $e->getMessage());
    json_out(['result' => 'error', 'message' => 'Interner Fehler – bitte später erneut versuchen.'], 500);
}

<?php
declare(strict_types=1);

/** Admin-Authentifizierung: Superadmin aus Umgebungsvariablen + weitere Admins aus der DB. */
final class Auth
{
    private const MAX_FAILS = 8;
    private const LOCK_SECONDS = 900;

    public static function superUser(): string
    {
        return (string)cfg('admin.user', 'admin');
    }

    private static function superPass(): string
    {
        return (string)cfg('admin.pass', '');
    }

    public static function attempt(string $user, string $pass): ?string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip = trim(explode(',', $ip)[0]);
        $key = 'login_fail:' . sha1($ip);
        $fails = (int)(Cache::get($key) ?? 0);
        if ($fails >= self::MAX_FAILS) {
            return 'Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.';
        }

        $ok = false;
        $super = false;
        $superPass = self::superPass();
        if ($superPass !== '' && hash_equals(mb_strtolower(self::superUser()), mb_strtolower($user)) && hash_equals($superPass, $pass)) {
            $ok = $super = true;
            $user = self::superUser();
        } else {
            $stmt = db()->prepare('SELECT username, password_hash FROM admins WHERE username = ?');
            $stmt->execute([$user]);
            if (($row = $stmt->fetch()) && password_verify($pass, $row['password_hash'])) {
                $ok = true;
                $user = $row['username'];
            }
        }

        if (!$ok) {
            Cache::set($key, $fails + 1, self::LOCK_SECONDS);
            usleep(500000);
            return 'Falscher Benutzername oder Passwort.';
        }
        Cache::delete($key);
        start_session();
        session_regenerate_id(true);
        $_SESSION['admin'] = ['username' => $user, 'super' => $super];
        return null;
    }

    public static function user(): ?array
    {
        start_session();
        $a = $_SESSION['admin'] ?? null;
        if (!$a) {
            return null;
        }
        // Gelöschte Web-Admins sofort ausloggen
        if (!$a['super']) {
            $stmt = db()->prepare('SELECT 1 FROM admins WHERE username = ?');
            $stmt->execute([$a['username']]);
            if (!$stmt->fetchColumn()) {
                unset($_SESSION['admin']);
                return null;
            }
        }
        return $a;
    }

    public static function require(bool $super = false): array
    {
        $u = self::user();
        if (!$u) {
            redirect('/admin/login.php');
        }
        if ($super && !$u['super']) {
            http_response_code(403);
            exit('Nur für den Superadmin.');
        }
        return $u;
    }

    public static function logout(): void
    {
        start_session();
        $_SESSION = [];
        session_destroy();
    }
}

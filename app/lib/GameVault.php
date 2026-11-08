<?php
declare(strict_types=1);

/** GameVault-API-Client (Basic-Login -> JWT Bearer). */
final class GameVault
{
    /** @return array{url:string, username:string, password:string}|null */
    public static function credentials(): ?array
    {
        $c = [
            'url'      => (string)cfg('gamevault.url', ''),
            'username' => (string)cfg('gamevault.username', ''),
            'password' => (string)cfg('gamevault.password', ''),
        ];
        return in_array('', $c, true) ? null : $c;
    }

    public static function isConfigured(): bool
    {
        return self::credentials() !== null;
    }

    public static function baseUrl(): string
    {
        return self::credentials()['url'] ?? '';
    }

    /** Link zur Spielseite in der GameVault-Weboberfläche */
    public static function gameUrl(?int $gvId): ?string
    {
        $base = self::baseUrl();
        return ($gvId && $base !== '') ? $base . '/library/' . $gvId : null;
    }

    private static function login(): string
    {
        $c = self::credentials();
        if (!$c) {
            throw new RuntimeException('GameVault ist nicht konfiguriert.');
        }
        $res = Http::request('GET', $c['url'] . '/api/auth/basic/login', [
            'Authorization: Basic ' . base64_encode($c['username'] . ':' . $c['password']),
            'Accept: application/json',
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] === 200 && !empty($data['access_token'])) {
            $token = (string)$data['access_token'];
            $ttl = 600;
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                if (!empty($payload['exp'])) {
                    $ttl = max(30, (int)$payload['exp'] - time() - 60);
                }
            }
            Cache::set('gv:token', $token, $ttl);
            return $token;
        }
        throw new RuntimeException('GameVault-Login fehlgeschlagen (Zugangsdaten prüfen).');
    }

    public static function get(string $path, array $query = []): mixed
    {
        $url = self::baseUrl() . $path . ($query ? '?' . http_build_query($query) : '');
        for ($try = 0; $try < 2; $try++) {
            $token = ($try === 0 ? Cache::get('gv:token') : null) ?? self::login();
            $res = Http::request('GET', $url, ['Authorization: Bearer ' . $token, 'Accept: application/json'], null, 60);
            if ($res['status'] === 401 && $try === 0) {
                Cache::delete('gv:token');
                continue;
            }
            if ($res['status'] !== 200) {
                throw new RuntimeException("GameVault {$path}: HTTP {$res['status']}");
            }
            return json_decode($res['body'], true);
        }
        throw new RuntimeException("GameVault {$path}: Nicht autorisiert");
    }

    /** Rohdaten eines Mediums (Cover/Hintergrund); null wenn nicht vorhanden */
    public static function media(int $id): ?string
    {
        for ($try = 0; $try < 2; $try++) {
            $token = ($try === 0 ? Cache::get('gv:token') : null) ?? self::login();
            $res = Http::request('GET', self::baseUrl() . '/api/media/' . $id, ['Authorization: Bearer ' . $token], null, 30);
            if ($res['status'] === 401 && $try === 0) {
                Cache::delete('gv:token');
                continue;
            }
            return $res['status'] === 200 && $res['body'] !== '' ? $res['body'] : null;
        }
        return null;
    }

    /** Alle Spiele (paginiert abgeholt) */
    public static function games(): array
    {
        $all = [];
        $page = 1;
        do {
            $res = self::get('/api/games', ['limit' => 200, 'page' => $page]);
            $all = array_merge($all, $res['data'] ?? []);
            $totalPages = (int)($res['meta']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page < 100);
        return $all;
    }

    public static function users(): array
    {
        $res = self::get('/api/users');
        return is_array($res) ? ($res['data'] ?? $res) : [];
    }
}

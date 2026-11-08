<?php
declare(strict_types=1);

/** Minimaler HTTP-Client (cURL) mit optionalem DB-Cache für GET-Anfragen. */
final class Http
{
    /**
     * @return array{status:int, body:string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'WishVault/1.0',
            CURLOPT_ENCODING       => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        if ($res === false) {
            throw new RuntimeException("HTTP-Fehler bei {$url}: {$err}");
        }
        return ['status' => $status, 'body' => (string)$res];
    }

    /** GET + JSON-Dekodierung, Ergebnis wird $ttl Sekunden gecacht (0 = kein Cache). */
    public static function getJson(string $url, int $ttl = 0, array $headers = []): ?array
    {
        if ($ttl > 0 && ($cached = Cache::get('http:' . $url)) !== null) {
            return $cached;
        }
        $res = self::request('GET', $url, $headers);
        if ($res['status'] !== 200) {
            return null;
        }
        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return null;
        }
        if ($ttl > 0) {
            Cache::set('http:' . $url, $data, $ttl);
        }
        return $data;
    }
}

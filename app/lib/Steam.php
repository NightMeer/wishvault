<?php
declare(strict_types=1);

/** Steam Store / SteamCMD-Anbindung (keine API-Keys nötig). */
final class Steam
{
    /** AppID aus Steam-/SteamDB-Link oder reiner Zahl extrahieren */
    public static function parseAppId(string $input): ?int
    {
        $input = trim($input);
        if (preg_match('~^\d{1,10}$~', $input)) {
            return (int)$input;
        }
        if (preg_match('~(?:store\.steampowered\.com|steamcommunity\.com|steamdb\.info)/app/(\d+)~i', $input, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    /**
     * Live-Suche im Steam-Store.
     * @return list<array{appid:int, name:string, image:string}>
     */
    public static function search(string $term): array
    {
        $appid = self::parseAppId($term);
        if ($appid !== null) {
            $d = self::appDetails($appid);
            return $d ? [['appid' => $d['appid'], 'name' => $d['name'], 'image' => $d['header_image']]] : [];
        }
        $url = 'https://store.steampowered.com/api/storesearch/?' . http_build_query([
            'term' => $term,
            'l'    => cfg('steam.language', 'german'),
            'cc'   => cfg('steam.country', 'DE'),
        ]);
        $data = Http::getJson($url, 3600);
        $out = [];
        foreach ($data['items'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'app') {
                continue;
            }
            $out[] = [
                'appid' => (int)$item['id'],
                'name'  => (string)$item['name'],
                'image' => !empty($item['tiny_image'])
                    ? preg_replace('~capsule_231x87\.jpg~', 'header.jpg', (string)$item['tiny_image'])
                    : steam_header((int)$item['id']),
            ];
        }
        return $out;
    }

    /**
     * App-Details (Name, Typ, Release-Status). $ttl = Cache-Dauer, 0 erzwingt frische Abfrage.
     * @return array{appid:int,name:string,type:string,coming_soon:bool,release_date:string,header_image:string}|null
     */
    public static function appDetails(int $appid, int $ttl = 21600): ?array
    {
        $key = 'steam:app:' . $appid;
        if ($ttl > 0 && ($c = Cache::get($key)) !== null) {
            return $c ?: null;
        }
        $url = 'https://store.steampowered.com/api/appdetails?' . http_build_query([
            'appids'  => $appid,
            'l'       => cfg('steam.language', 'german'),
            'cc'      => cfg('steam.country', 'DE'),
            'filters' => 'basic,release_date',
        ]);
        $data = Http::getJson($url);
        $entry = $data[(string)$appid] ?? null;
        if (!$entry || empty($entry['success']) || empty($entry['data'])) {
            Cache::set($key, [], 3600); // Fehlschlag kurz cachen
            return null;
        }
        $d = $entry['data'];
        $res = [
            'appid'        => $appid,
            'name'         => (string)($d['name'] ?? ('App ' . $appid)),
            'type'         => (string)($d['type'] ?? 'game'),
            'coming_soon'  => (bool)($d['release_date']['coming_soon'] ?? false),
            'release_date' => (string)($d['release_date']['date'] ?? ''),
            'header_image' => (string)($d['header_image'] ?? steam_header($appid)),
        ];
        Cache::set($key, $res, max($ttl, 3600));
        return $res;
    }

    /**
     * Steam-Nutzer-Tags (nach Gewichtung sortiert), 7 Tage gecacht.
     * @return list<string>
     */
    public static function tags(int $appid, ?string $language = null, int $limit = 20): array
    {
        $language ??= (string)cfg('steam.tags_language', 'english');
        $key = "steam:tags:{$appid}:{$language}";
        if (($c = Cache::get($key)) !== null) {
            return $c;
        }
        $names = Cache::get('steam:taglist:' . $language);
        if ($names === null) {
            $list = Http::getJson('https://api.steampowered.com/IStoreService/GetTagList/v1/?' . http_build_query(['language' => $language]));
            $names = [];
            foreach ($list['response']['tags'] ?? [] as $t) {
                $names[(string)$t['tagid']] = (string)$t['name'];
            }
            if ($names) {
                Cache::set('steam:taglist:' . $language, $names, 86400 * 7);
            }
        }
        $data = Http::getJson('https://api.steampowered.com/IStoreBrowseService/GetItems/v1/?' . http_build_query([
            'input_json' => json_encode([
                'ids'          => [['appid' => $appid]],
                'context'      => ['language' => $language, 'country_code' => cfg('steam.country', 'DE')],
                'data_request' => ['include_tag_count' => $limit],
            ]),
        ]));
        $item = $data['response']['store_items'][0] ?? [];
        $tags = $item['tags'] ?? [];
        usort($tags, fn($a, $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));
        $out = [];
        foreach ($tags as $t) {
            if (isset($names[(string)$t['tagid']])) {
                $out[] = $names[(string)$t['tagid']];
            }
        }
        Cache::set($key, $out, $out ? 86400 * 7 : 3600);
        return $out;
    }

    /**
     * Build-Infos des öffentlichen Branches über api.steamcmd.net.
     * @return array{buildid:string, timeupdated:int}|null
     */
    public static function buildInfo(int $appid): ?array
    {
        $data = Http::getJson('https://api.steamcmd.net/v1/info/' . $appid);
        $pub = $data['data'][(string)$appid]['depots']['branches']['public'] ?? null;
        if (($data['status'] ?? '') !== 'success' || !$pub) {
            return null;
        }
        return [
            'buildid'     => (string)($pub['buildid'] ?? ''),
            'timeupdated' => (int)($pub['timeupdated'] ?? 0),
        ];
    }
}

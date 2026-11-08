<?php
declare(strict_types=1);

/** Schlanker SMTP-Client (STARTTLS / SSL, AUTH LOGIN), ohne externe Abhängigkeiten. */
final class Mailer
{
    /** @var resource|null */
    private $sock = null;

    public static function isConfigured(): bool
    {
        return cfg('smtp.host', '') !== '' && cfg('smtp.from_email', '') !== '';
    }

    public static function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('SMTP ist nicht konfiguriert.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Ungültige Empfängeradresse.');
        }
        $m = new self();
        try {
            $m->connect();
            $m->transmit($to, $subject, $text, $html);
        } finally {
            $m->close();
        }
    }

    private function connect(): void
    {
        $host = (string)cfg('smtp.host');
        $port = (int)cfg('smtp.port', 587);
        $enc  = (string)cfg('smtp.encryption', 'tls');
        $remote = ($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $sock = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: {$errstr} ({$errno})");
        }
        stream_set_timeout($sock, 20);
        $this->sock = $sock;
        $this->expect(220);
        $ehloHost = parse_url((string)cfg('base_url', ''), PHP_URL_HOST) ?: 'localhost';
        $this->cmd('EHLO ' . $ehloHost, 250);
        if ($enc === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS fehlgeschlagen.');
            }
            $this->cmd('EHLO ' . $ehloHost, 250);
        }
        $user = (string)cfg('smtp.username', '');
        if ($user !== '') {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($user), 334);
            $this->cmd(base64_encode((string)cfg('smtp.password', '')), 235);
        }
    }

    private function transmit(string $to, string $subject, string $text, ?string $html): void
    {
        $from = (string)cfg('smtp.from_email');
        $fromName = (string)cfg('smtp.from_name', '');
        $this->cmd('MAIL FROM:<' . $from . '>', 250);
        $this->cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $this->cmd('DATA', 354);

        $enc = fn(string $s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName !== '' ? $enc($fromName) . ' ' : '') . '<' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr(strrchr($from, '@'), 1) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
        ];
        if ($html !== null) {
            $b = 'b' . bin2hex(random_bytes(8));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
            $body = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($text))
                . "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($html))
                . "--{$b}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($text));
        }
        $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        // Dot-Stuffing
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($this->sock, $data . "\r\n.\r\n");
        $this->expect(250);
        $this->cmd('QUIT', [221, 250]);
    }

    private function cmd(string $line, int|array $expect): string
    {
        fwrite($this->sock, $line . "\r\n");
        return $this->expect($expect);
    }

    private function expect(int|array $codes): string
    {
        $codes = (array)$codes;
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP-Fehler: ' . trim($resp));
        }
        return $resp;
    }

    private function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }
}

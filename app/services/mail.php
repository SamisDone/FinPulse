<?php
/**
 * Outgoing email: a small queue, MIME building, and drivers for SMTP, PHP's mail(),
 * a log driver that writes .eml files (the default, handy during development), or none.
 */
defined('SIXPENCE') || exit;

const MAIL_MAX_ATTEMPTS = 5;

function mail_driver(): string
{
    $driver = strtolower((string) env('MAIL_DRIVER', 'log'));
    return in_array($driver, ['smtp', 'mail', 'log', 'none'], true) ? $driver : 'log';
}

function mail_enabled(): bool
{
    return mail_driver() !== 'none';
}

function mail_log_dir(): string
{
    return project_path(env('MAIL_LOG_PATH', 'storage/mail'));
}

function mail_from(): array
{
    $host = parse_url(app_url(), PHP_URL_HOST) ?: 'localhost';
    $domain = str_contains($host, '.') && !filter_var($host, FILTER_VALIDATE_IP) ? $host : 'localhost.localdomain';
    $address = env('MAIL_FROM_ADDRESS', 'sixpence@' . $domain);
    return [$address, env('MAIL_FROM_NAME', 'Sixpence')];
}

/* ---------------------------------------------------------------------------
 * Queue
 * ------------------------------------------------------------------------ */

/**
 * Queue an email. It's sent after the response has gone out (or by scripts/cron.php),
 * so a slow mail server never holds up a page.
 *
 * @param array{text: string, html?: string} $body
 */
function queue_mail(string $to_email, ?string $to_name, string $subject, array $body): void
{
    if (!mail_enabled() || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $now = time();
    db()->prepare('INSERT INTO mail_queue (to_email, to_name, subject, text_body, html_body, available_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$to_email, $to_name, $subject, $body['text'], $body['html'] ?? null, $now, $now]);
    schedule_mail_flush();
}

function schedule_mail_flush(): void
{
    static $scheduled = false;
    if ($scheduled || PHP_SAPI === 'cli') {
        return;
    }
    $scheduled = true;
    register_shutdown_function(static function (): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        try {
            flush_mail_queue(10);
        } catch (Throwable $e) {
            error_log('[Sixpence] Mail queue: ' . $e->getMessage());
        }
    });
}

/**
 * Send pending emails. Failures retry with exponential backoff, up to MAIL_MAX_ATTEMPTS.
 *
 * @return array{sent: int, failed: int, errors: string[]}
 */
function flush_mail_queue(int $limit = 50): array
{
    $result = ['sent' => 0, 'failed' => 0, 'errors' => []];
    $now = time();
    $rows = db()->prepare('SELECT * FROM mail_queue WHERE sent_at IS NULL AND attempts < ? AND available_at <= ? ORDER BY id LIMIT ' . max(1, $limit));
    $rows->execute([MAIL_MAX_ATTEMPTS, $now]);

    foreach ($rows->fetchAll() as $row) {
        // Claim the row so two concurrent flushes can't send the same message.
        $claim = db()->prepare('UPDATE mail_queue SET available_at = ? WHERE id = ? AND available_at <= ? AND sent_at IS NULL');
        $claim->execute([$now + 300, $row['id'], $now]);
        if ($claim->rowCount() !== 1) {
            continue;
        }
        try {
            send_mail($row['to_email'], $row['to_name'], $row['subject'], ['text' => $row['text_body'], 'html' => $row['html_body']]);
            db()->prepare('UPDATE mail_queue SET sent_at = ?, last_error = NULL WHERE id = ?')->execute([time(), $row['id']]);
            $result['sent']++;
        } catch (Throwable $e) {
            $attempts = (int) $row['attempts'] + 1;
            db()->prepare('UPDATE mail_queue SET attempts = ?, last_error = ?, available_at = ? WHERE id = ?')
                ->execute([$attempts, mb_substr($e->getMessage(), 0, 500), time() + 60 * (2 ** $attempts), $row['id']]);
            error_log('[Sixpence] Email to ' . $row['to_email'] . ' failed: ' . $e->getMessage());
            $result['failed']++;
            $result['errors'][] = $e->getMessage();
        }
    }
    return $result;
}

/* ---------------------------------------------------------------------------
 * Sending
 * ------------------------------------------------------------------------ */

/**
 * Send one message immediately with the configured driver. Throws on failure.
 *
 * @param array{text: string, html?: ?string} $body
 * @return string|null the .eml path when using the log driver
 */
function send_mail(string $to_email, ?string $to_name, string $subject, array $body): ?string
{
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Invalid recipient address.');
    }
    [$from_email, $from_name] = mail_from();
    [$headers, $mime_body] = build_mime($from_email, $from_name, $to_email, $to_name, $subject, $body);
    $raw = 'To: ' . format_address($to_email, $to_name) . "\r\nSubject: " . encode_header($subject) . "\r\n" . $headers . "\r\n\r\n" . $mime_body;

    switch (mail_driver()) {
        case 'none':
            throw new RuntimeException('Email is turned off (MAIL_DRIVER=none).');

        case 'log':
            $dir = mail_log_dir();
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException("Can't create the mail log folder $dir.");
            }
            // Microseconds in the name keep files in the order they were written.
            $now = microtime(true);
            $file = sprintf('%s/%s-%06d-%s.eml', $dir, date('Ymd-His', (int) $now), (int) (($now - floor($now)) * 1_000_000), bin2hex(random_bytes(3)));
            if (file_put_contents($file, $raw) === false) {
                throw new RuntimeException("Can't write $file.");
            }
            return $file;

        case 'mail':
            if (!mail(format_address($to_email, $to_name), encode_header($subject), $mime_body, $headers, '-f' . $from_email)) {
                throw new RuntimeException('PHP mail() refused the message. Check your server\'s sendmail setup.');
            }
            return null;

        default:
            $port = (int) env('MAIL_PORT', '587');
            $encryption = strtolower((string) env('MAIL_ENCRYPTION', $port === 465 ? 'ssl' : ($port === 25 ? 'none' : 'tls')));
            $client = new SmtpClient(
                (string) env('MAIL_HOST', '127.0.0.1'),
                $port,
                $encryption,
                env('MAIL_USERNAME'),
                env('MAIL_PASSWORD'),
                (int) env('MAIL_TIMEOUT', '15')
            );
            $client->send($from_email, [$to_email], $raw);
            return null;
    }
}

/** @return array{0: string, 1: string} headers (without To/Subject) and the MIME body */
function build_mime(string $from_email, string $from_name, string $to_email, ?string $to_name, string $subject, array $body): array
{
    $boundary = 'fp-' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($from_email, '@') ?: '@localhost', 1);
    $headers = implode("\r\n", [
        'From: ' . format_address($from_email, $from_name),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'X-Mailer: Sixpence/' . APP_VERSION,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ]);

    $part = static fn(string $type, string $content): string => "--$boundary\r\n"
        . "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . rtrim(chunk_split(base64_encode($content), 76, "\r\n")) . "\r\n";

    $mime = $part('text/plain', $body['text']);
    if (!empty($body['html'])) {
        $mime .= $part('text/html', $body['html']);
    }
    return [$headers, $mime . "--$boundary--\r\n"];
}

function encode_header(string $value): string
{
    $value = preg_replace('/[\r\n]+/', ' ', $value);
    return preg_match('/^[\x20-\x7E]*$/', $value) ? $value : '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function format_address(string $email, ?string $name): string
{
    $email = str_replace(["\r", "\n", '<', '>'], '', $email);
    if ($name === null || trim($name) === '') {
        return $email;
    }
    $name = preg_replace('/[\r\n]+/', ' ', $name);
    $display = preg_match('/^[\x20-\x7E]*$/', $name) ? '"' . addcslashes($name, '"\\') . '"' : encode_header($name);
    return $display . ' <' . $email . '>';
}

/**
 * Minimal SMTP client: implicit TLS (ssl), STARTTLS (tls) or plain, with AUTH PLAIN/LOGIN.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private ?string $username,
        private ?string $password,
        private int $timeout = 15,
    ) {
    }

    /** @param string[] $recipients */
    public function send(string $from, array $recipients, string $message): void
    {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host, 'SNI_enabled' => true]]);
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            throw new RuntimeException("Couldn't connect to the mail server {$this->host}:{$this->port} ($errstr).");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->read([220]);
            $capabilities = $this->command('EHLO ' . $this->helloName(), [250]);

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
                if (!@stream_socket_enable_crypto($socket, true, $method)) {
                    throw new RuntimeException('The mail server\'s TLS handshake failed.');
                }
                $capabilities = $this->command('EHLO ' . $this->helloName(), [250]);
            }

            if ($this->username !== null && $this->username !== '') {
                if (preg_match('/^250[ -]AUTH\b.*\bPLAIN\b/mi', $capabilities)) {
                    $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password), [235], 'AUTH PLAIN');
                } else {
                    $this->command('AUTH LOGIN', [334]);
                    $this->command(base64_encode($this->username), [334], 'username');
                    $this->command(base64_encode((string) $this->password), [235], 'password');
                }
            }

            $this->command('MAIL FROM:<' . $from . '>', [250]);
            foreach ($recipients as $recipient) {
                $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
            }
            $this->command('DATA', [354]);

            // Normalise line endings and dot-stuff lines that start with a period (RFC 5321 §4.5.2).
            $data = preg_replace("/\r\n|\r|\n/", "\r\n", $message);
            $data = preg_replace('/^\./m', '..', $data);
            $this->write($data . "\r\n.\r\n");
            $this->read([250]);

            try {
                $this->command('QUIT', [221]);
            } catch (RuntimeException) {
                // The message was accepted; a sloppy goodbye doesn't matter.
            }
        } finally {
            fclose($socket);
            $this->socket = null;
        }
    }

    private function helloName(): string
    {
        $host = parse_url(app_url(), PHP_URL_HOST);
        return $host && preg_match('/^[a-z0-9.-]+$/i', $host) ? $host : 'localhost';
    }

    /** @param int[] $expected */
    private function command(string $line, array $expected, ?string $redacted = null): string
    {
        $this->write($line . "\r\n");
        return $this->read($expected, $redacted ?? $line);
    }

    private function write(string $data): void
    {
        for ($written = 0; $written < strlen($data); $written += $bytes) {
            $bytes = fwrite($this->socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Lost the connection to the mail server while sending.');
            }
        }
    }

    /** @param int[] $expected */
    private function read(array $expected, string $context = 'greeting'): string
    {
        $response = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        if (stream_get_meta_data($this->socket)['timed_out']) {
            throw new RuntimeException("The mail server timed out ($context).");
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('The mail server rejected ' . $context . ': ' . trim($response ?: 'no response'));
        }
        return $response;
    }
}

/* ---------------------------------------------------------------------------
 * Templates
 * ------------------------------------------------------------------------ */

/**
 * A plain, branded email with an optional button and key/value rows.
 *
 * @param string[] $paragraphs
 * @param array{0: string, 1: string}|null $button [label, absolute URL]
 * @param array<string, string> $rows label => value
 * @return array{text: string, html: string}
 */
function email_template(string $heading, array $paragraphs, ?array $button = null, array $rows = []): array
{
    $text = $heading . "\n" . str_repeat('=', mb_strlen($heading)) . "\n\n" . implode("\n\n", $paragraphs);
    if ($rows) {
        $text .= "\n\n" . implode("\n", array_map(static fn($k, $v) => "$k: $v", array_keys($rows), $rows));
    }
    if ($button) {
        $text .= "\n\n{$button[0]}:\n{$button[1]}";
    }
    $text .= "\n\n--\nSixpence · " . app_url() . "\nManage notifications: " . absolute_url('settings', [], 'notifications') . "\n";

    $p = implode('', array_map(static fn($para) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.55;color:#57544c">' . e($para) . '</p>', $paragraphs));
    $table = '';
    if ($rows) {
        $table = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px;border-top:1px solid #e4e0d7">';
        foreach ($rows as $label => $value) {
            $table .= '<tr><td style="padding:10px 0;border-bottom:1px solid #e4e0d7;font-size:14px;color:#85817a">' . e($label) . '</td>'
                . '<td align="right" style="padding:10px 0;border-bottom:1px solid #e4e0d7;font-size:14px;color:#1c1b18;font-weight:600">' . e($value) . '</td></tr>';
        }
        $table .= '</table>';
    }
    $cta = $button
        ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 22px"><tr><td style="border-radius:8px;background:#1c1b18">'
          . '<a href="' . e($button[1]) . '" style="display:inline-block;padding:12px 20px;font-size:15px;font-weight:600;color:#faf9f6;text-decoration:none">' . e($button[0]) . '</a></td></tr></table>'
          . '<p style="margin:0 0 14px;font-size:12px;line-height:1.5;color:#85817a">If the button doesn\'t work, paste this link into your browser:<br><span style="word-break:break-all">' . e($button[1]) . '</span></p>'
        : '';

    $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f5f3ee">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f3ee;padding:32px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif">'
        . '<tr><td style="padding:0 4px 16px;font-size:16px;font-weight:600;color:#1c1b18">'
        . '<span style="display:inline-block;width:22px;height:22px;border-radius:6px;background:#1c1b18;vertical-align:middle;margin-right:8px"></span>Sixpence</td></tr>'
        . '<tr><td style="background:#ffffff;border:1px solid #e4e0d7;border-radius:14px;padding:28px">'
        . '<h1 style="margin:0 0 14px;font-family:Georgia,Times New Roman,serif;font-weight:400;font-size:28px;line-height:1.15;color:#1c1b18">' . e($heading) . '</h1>'
        . $p . $table . $cta
        . '</td></tr>'
        . '<tr><td style="padding:16px 4px;font-size:12px;line-height:1.5;color:#85817a">Sent by Sixpence at ' . e(app_url()) . '. '
        . '<a href="' . e(absolute_url('settings', [], 'notifications')) . '" style="color:#0b6840">Manage notifications</a></td></tr>'
        . '</table></td></tr></table></body></html>';

    return ['text' => $text, 'html' => $html];
}

<?php
/**
 * A throwaway SMTP server for tests: accepts one connection, records the conversation.
 *   php fake-smtp.php <port> <transcript file> [reject-auth]
 */
[$script, $port, $transcript] = $argv + [null, null, null];
$reject_auth = in_array('reject-auth', $argv, true);

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, $errstr);
    exit(1);
}
echo "listening\n";
$client = stream_socket_accept($server, 20);
$log = fopen($transcript, 'w');
$say = static function (string $line) use ($client, $log): void {
    fwrite($client, $line . "\r\n");
    fwrite($log, "S: $line\n");
};

$say('220 fake.smtp ready');
$in_data = false;
while (($line = fgets($client)) !== false) {
    fwrite($log, 'C: ' . rtrim($line, "\r\n") . "\n");
    if ($in_data) {
        if (rtrim($line, "\r\n") === '.') {
            $in_data = false;
            $say('250 queued');
        }
        continue;
    }
    $command = strtoupper(substr(trim($line), 0, 4));
    match (true) {
        $command === 'EHLO' => $say("250-fake.smtp\r\n250-AUTH PLAIN LOGIN\r\n250 OK"),
        $command === 'AUTH' => $say($reject_auth ? '535 authentication failed' : '235 accepted'),
        $command === 'MAIL', $command === 'RCPT' => $say('250 OK'),
        $command === 'DATA' => (function () use ($say, &$in_data) { $in_data = true; $say('354 go ahead'); })(),
        $command === 'QUIT' => $say('221 bye'),
        default => $say('500 unknown'),
    };
    if ($command === 'QUIT' || ($reject_auth && $command === 'AUTH')) {
        break;
    }
}
fclose($client);
fclose($log);

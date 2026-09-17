<?php

/** Start the fake SMTP server and wait for it to listen. */
function start_fake_smtp(array $extra = []): array
{
    $port = free_port();
    $transcript = getenv('FINPULSE_TEST_DIR') . "/smtp-$port.log";
    $process = proc_open([PHP_BINARY, __DIR__ . '/../fixtures/fake-smtp.php', (string) $port, $transcript, ...$extra], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    // The fake server accepts a single connection, so wait for its signal instead of probing the port.
    if (trim((string) fgets($pipes[1])) !== 'listening') {
        fail('Fake SMTP server did not start: ' . stream_get_contents($pipes[2]));
    }
    return [$port, $transcript, $process];
}

test('log driver writes a valid MIME message with text and HTML parts', function () {
    $file = send_mail('ana@example.test', 'Ana Lima', 'Olá, résumé', email_template('Heading', ['Paragraph one.'], ['Open', 'https://example.test/x'], ['Spent' => '$10.00']));
    $raw = file_get_contents($file);
    expect_contains('To: "Ana Lima" <ana@example.test>', $raw);
    expect_contains('Subject: =?UTF-8?B?' . base64_encode('Olá, résumé') . '?=', $raw);
    expect_contains('Content-Type: multipart/alternative', $raw);
    expect_contains('Content-Type: text/html; charset=UTF-8', $raw);
    $text = email_text($raw);
    expect_contains('Paragraph one.', $text);
    expect_contains('Spent: $10.00', $text);
    expect_contains('https://example.test/x', $text);
});

test('header values cannot inject extra headers', function () {
    expect_same('Evil Bcc: x@y.z', encode_header("Evil\r\nBcc: x@y.z"));
    expect_not_contains("\n", format_address("a@b.c\r\nBcc: x@y.z", "Name\r\nBcc: x"));
    expect_throws(fn() => send_mail("bad\r\n@example.test", null, 'x', ['text' => 'x']), 'Invalid recipient');
});

test('SMTP client authenticates, sends and dot-stuffs the message', function () {
    [$port, $transcript, $process] = start_fake_smtp();
    $client = new SmtpClient('127.0.0.1', $port, 'none', 'mailer', 's3cret', 5);
    $client->send('from@example.test', ['to@example.test'], "Subject: Hi\r\n\r\nLine one\r\n.hidden dot line\r\nEnd");
    proc_close($process);

    $log = file_get_contents($transcript);
    expect_contains('C: AUTH PLAIN ' . base64_encode("\0mailer\0s3cret"), $log);
    expect_contains('C: MAIL FROM:<from@example.test>', $log);
    expect_contains('C: RCPT TO:<to@example.test>', $log);
    expect_contains('C: ..hidden dot line', $log, 'leading dots are doubled');
    expect_contains('C: QUIT', $log);
});

test('SMTP errors are reported without leaking the password', function () {
    [$port, , $process] = start_fake_smtp(['reject-auth']);
    $client = new SmtpClient('127.0.0.1', $port, 'none', 'mailer', 'top-secret-pw', 5);
    $error = expect_throws(fn() => $client->send('a@example.test', ['b@example.test'], 'x'), 'rejected AUTH PLAIN');
    proc_close($process);
    expect_not_contains('top-secret-pw', $error->getMessage());
    expect_not_contains(base64_encode("\0mailer\0top-secret-pw"), $error->getMessage());
});

test('mail queue retries failures with backoff and marks successes sent', function () {
    $user = make_user('queue');
    queue_mail($user['email'], $user['username'], 'Queued', ['text' => 'hello']);
    $result = flush_mail_queue();
    expect_true($result['sent'] >= 1);
    expect_same(1, count_rows('SELECT COUNT(*) FROM mail_queue WHERE to_email = ? AND sent_at IS NOT NULL', [$user['email']]));

    putenv('MAIL_DRIVER=smtp');
    putenv('MAIL_HOST=127.0.0.1');
    putenv('MAIL_PORT=' . free_port());
    putenv('MAIL_ENCRYPTION=none');
    putenv('MAIL_TIMEOUT=1');
    try {
        queue_mail($user['email'], null, 'Will fail', ['text' => 'x']);
        $failed = flush_mail_queue();
        expect_same(1, $failed['failed']);
        $row = db()->prepare("SELECT attempts, last_error, available_at FROM mail_queue WHERE subject = 'Will fail'");
        $row->execute();
        $row = $row->fetch();
        expect_same(1, (int) $row['attempts']);
        expect_contains("Couldn't connect", $row['last_error']);
        expect_true((int) $row['available_at'] > time() + 60, 'retry is pushed back');
        expect_same(0, flush_mail_queue()['failed'], 'not retried immediately');
    } finally {
        putenv('MAIL_DRIVER=log');
    }
});

test('report PDF is valid, multi-page and has real searchable text', function () {
    $user = make_user('pdf');
    for ($i = 0; $i < 30; $i++) {
        add_expense($user, 10 + $i, sprintf('2026-08-%02d', $i % 28 + 1), 'Category ' . ($i % 15), 'Item (' . $i . ') \\ test');
    }
    add_income($user, 5000, '2026-08-01');
    use_currency('USD');
    $pdf = build_report_pdf(build_report($user['id'], '2026-08-01', '2026-08-31'), $user);

    expect_same('%PDF-1.4', substr($pdf, 0, 8));
    expect_same("%%EOF\n", substr($pdf, -6));
    preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m);
    expect_same('xref', substr($pdf, (int) $m[1], 4), 'startxref points at the xref table');

    preg_match_all('/(\d+) 0 obj/', $pdf, $objects, PREG_OFFSET_CAPTURE);
    preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offsets);
    foreach ($objects[1] as $i => [$number, $offset]) {
        expect_same((int) $offsets[1][$i], $objects[0][$i][1], "xref offset for object $number");
    }

    preg_match('~/Count (\d+)~', $pdf, $count);
    expect_true((int) $count[1] >= 2, 'the long category table spills onto a second page');

    $text = '';
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $streams);
    foreach ($streams[1] as $stream) {
        $text .= gzuncompress($stream);
    }
    expect_contains('(Income & spending report) Tj', $text);
    expect_contains('(Spending by category) Tj', $text);
    expect_contains('($5,000.00) Tj', $text);
    expect_contains('Item \\(29\\) \\\\ test', $text, 'parentheses and backslashes are escaped');
});

test('PDF falls back to currency codes for symbols the built-in font lacks', function () {
    expect_true(PdfDocument::canEncode('€'));
    expect_false(PdfDocument::canEncode('৳'));
    use_currency('BDT');
    expect_same('BDT 1,250.00', pdf_money(1250));
    use_currency('EUR');
    expect_same('€1,250.00', pdf_money(1250));
    expect_same([0.0, 250.0, 500.0, 750.0, 1000.0], pdf_ticks(0, 900));
});

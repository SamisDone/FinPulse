<?php
/**
 * A small, dependency-free PDF writer: vector shapes and real (selectable, searchable) text
 * in the built-in Helvetica fonts. Coordinates are in points from the top-left corner.
 */
defined('FINPULSE') || exit;

final class PdfDocument
{
    public const WIDTH = 595.28;   // A4
    public const HEIGHT = 841.89;

    /** Adobe Helvetica / Helvetica-Bold advance widths for Windows-1252 code points 0-255. */
    private const WIDTHS_REGULAR = '278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500';
    private const WIDTHS_BOLD = '278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556';

    /** @var string[] content stream per page */
    private array $pages = [];
    private bool $bold = false;
    private float $size = 10;
    /** @var array<string, int[]> */
    private static array $widths = [];

    public function __construct(private string $title, private string $author = 'FinPulse')
    {
    }

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Draw on every existing page, e.g. footers once the page count is known. */
    public function eachPage(callable $draw): void
    {
        $total = count($this->pages);
        $saved = $this->pages;
        foreach (array_keys($saved) as $index) {
            $this->pages = [''];
            $draw($index + 1, $total);
            $saved[$index] .= $this->pages[0];
        }
        $this->pages = $saved;
    }

    public function setFont(bool $bold, float $size): void
    {
        $this->bold = $bold;
        $this->size = $size;
    }

    public function text(float $x, float $y, string $text, string $color = '#1c1b18', string $align = 'left'): void
    {
        $encoded = self::encode($text);
        if ($align !== 'left') {
            $width = $this->widthOfEncoded($encoded, $this->bold, $this->size);
            $x -= $align === 'right' ? $width : $width / 2;
        }
        $this->write(sprintf(
            "BT /%s %s Tf %s %s %s Td %s Tj ET\n",
            $this->bold ? 'F2' : 'F1',
            self::n($this->size),
            self::rgb($color, 'rg'),
            self::n($x),
            self::n(self::HEIGHT - $y),
            self::literal($encoded)
        ));
    }

    public function textWidth(string $text, ?bool $bold = null, ?float $size = null): float
    {
        return $this->widthOfEncoded(self::encode($text), $bold ?? $this->bold, $size ?? $this->size);
    }

    /** Shorten text with an ellipsis so it fits in $max_width at the current font. */
    public function fit(string $text, float $max_width): string
    {
        if ($this->textWidth($text) <= $max_width) {
            return $text;
        }
        while ($text !== '' && $this->textWidth($text . '…') > $max_width) {
            $text = mb_substr($text, 0, -1);
        }
        return rtrim($text) . '…';
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill, ?string $stroke = null, float $line_width = 0.75, float $radius = 0): void
    {
        if ($w <= 0 || $h <= 0) {
            return;
        }
        $ops = ($fill ? self::rgb($fill, 'rg') . ' ' : '') . ($stroke ? self::rgb($stroke, 'RG') . ' ' . self::n($line_width) . ' w ' : '');
        $paint = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $top = self::HEIGHT - $y;
        $r = min($radius, $w / 2, $h / 2);

        if ($r <= 0) {
            $this->write($ops . sprintf("%s %s %s %s re %s\n", self::n($x), self::n($top - $h), self::n($w), self::n($h), $paint));
            return;
        }

        // Rounded rectangle from four Bézier corners.
        $k = 0.5523 * $r;
        $left = $x;
        $right = $x + $w;
        $bottom = $top - $h;
        $p = static fn(float ...$v) => implode(' ', array_map([self::class, 'n'], $v));
        $path = $p($left + $r, $top) . ' m '
            . $p($right - $r, $top) . ' l '
            . $p($right - $r + $k, $top, $right, $top - $r + $k, $right, $top - $r) . ' c '
            . $p($right, $bottom + $r) . ' l '
            . $p($right, $bottom + $r - $k, $right - $r + $k, $bottom, $right - $r, $bottom) . ' c '
            . $p($left + $r, $bottom) . ' l '
            . $p($left + $r - $k, $bottom, $left, $bottom + $r - $k, $left, $bottom + $r) . ' c '
            . $p($left, $top - $r) . ' l '
            . $p($left, $top - $r + $k, $left + $r - $k, $top, $left + $r, $top) . ' c ';
        $this->write($ops . $path . "h $paint\n");
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $width = 0.75): void
    {
        $this->write(self::rgb($color, 'RG') . ' ' . self::n($width) . ' w 1 J ' . $this->path([[$x1, $y1], [$x2, $y2]]) . "S\n");
    }

    /** @param array<array{0: float, 1: float}> $points */
    public function polyline(array $points, string $color, float $width = 1.5): void
    {
        if (count($points) >= 2) {
            $this->write(self::rgb($color, 'RG') . ' ' . self::n($width) . ' w 1 J 1 j ' . $this->path($points) . "S\n");
        }
    }

    /** @param array<array{0: float, 1: float}> $points */
    public function polygon(array $points, string $fill): void
    {
        if (count($points) >= 3) {
            $this->write(self::rgb($fill, 'rg') . ' ' . $this->path($points) . "h f\n");
        }
    }

    public function output(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $page_count = count($this->pages);
        $first_page = 6;
        $kids = implode(' ', array_map(static fn($i) => ($first_page + $i * 2) . ' 0 R', range(0, $page_count - 1)));

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => "<< /Type /Pages /Kids [$kids] /Count $page_count >>",
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => sprintf(
                '<< /Title %s /Author %s /Producer %s /CreationDate (D:%s) >>',
                self::literal(self::encode($this->title)),
                self::literal(self::encode($this->author)),
                self::literal('FinPulse ' . APP_VERSION),
                date('YmdHis')
            ),
        ];

        $compress = function_exists('gzcompress');
        foreach ($this->pages as $i => $content) {
            $page_id = $first_page + $i * 2;
            $stream = $compress ? gzcompress($content, 6) : $content;
            $objects[$page_id] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::n(self::WIDTH),
                self::n(self::HEIGHT),
                $page_id + 1
            );
            $objects[$page_id + 1] = '<< /Length ' . strlen($stream) . ($compress ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $stream . "\nendstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** Whether text can be shown exactly in the PDF's Windows-1252 font encoding. */
    public static function canEncode(string $text): bool
    {
        return @iconv('UTF-8', 'CP1252', $text) !== false;
    }

    private function write(string $ops): void
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $this->pages[array_key_last($this->pages)] .= $ops;
    }

    /** @param array<array{0: float, 1: float}> $points */
    private function path(array $points): string
    {
        $out = '';
        foreach (array_values($points) as $i => [$x, $y]) {
            $out .= self::n($x) . ' ' . self::n(self::HEIGHT - $y) . ($i === 0 ? ' m ' : ' l ');
        }
        return $out;
    }

    private function widthOfEncoded(string $encoded, bool $bold, float $size): float
    {
        $key = $bold ? 'bold' : 'regular';
        self::$widths[$key] ??= array_map('intval', explode(',', $bold ? self::WIDTHS_BOLD : self::WIDTHS_REGULAR));
        $units = 0;
        foreach (unpack('C*', $encoded) ?: [] as $byte) {
            $units += self::$widths[$key][$byte];
        }
        return $units * $size / 1000;
    }

    /** UTF-8 to Windows-1252, transliterating what it can and replacing the rest with "?". */
    private static function encode(string $text): string
    {
        $text = strtr($text, ["\u{2212}" => '-', "\u{00A0}" => ' ', "\u{202F}" => ' ']);
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $text);
        return $converted !== false ? $converted : mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    }

    private static function literal(string $bytes): string
    {
        return '(' . strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']) . ')';
    }

    private static function rgb(string $hex, string $operator): string
    {
        [$r, $g, $b] = array_map(static fn($c) => self::n(hexdec($c) / 255), str_split(ltrim($hex, '#'), 2));
        return "$r $g $b $operator";
    }

    private static function n(float $value): string
    {
        $out = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
        return $out === '' || $out === '-0' ? '0' : $out;
    }
}

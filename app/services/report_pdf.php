<?php
/**
 * Lays out a report as a vector PDF: summary, income vs spending chart, running balance,
 * and category/source/largest-expense tables. All text is real, selectable text.
 */
defined('SIXPENCE') || exit;

const PDF_MARGIN = 42;
const PDF_INK = '#1c1b18';
const PDF_INK_2 = '#57544c';
const PDF_INK_3 = '#85817a';
const PDF_LINE = '#e4e0d7';
const PDF_GRID = '#ece9e2';
const PDF_SUNKEN = '#eeebe4';
const PDF_IN = '#0e7a4b';
const PDF_IN_SOFT = '#dcece3';
const PDF_OUT = '#e0873a';
const PDF_NEG = '#a84a16';

/** Money for the PDF: symbols the built-in fonts can't draw (like ৳ or ₹) fall back to the currency code. */
function pdf_money(float $amount, string $sign = 'auto'): string
{
    $formatted = money($amount, $sign);
    $symbol = currency_symbol();
    return PdfDocument::canEncode($symbol) ? $formatted : str_replace($symbol, currency_code() . ' ', $formatted);
}

function pdf_money_short(float $amount): string
{
    $symbol = PdfDocument::canEncode(currency_symbol()) ? currency_symbol() : currency_code() . ' ';
    $abs = abs($amount);
    $value = match (true) {
        $abs >= 1_000_000 => rtrim(rtrim(number_format($abs / 1_000_000, 1), '0'), '.') . 'M',
        $abs >= 1000 => rtrim(rtrim(number_format($abs / 1000, 1), '0'), '.') . 'k',
        default => number_format($abs),
    };
    return ($amount < 0 ? '-' : '') . $symbol . $value;
}

function build_report_pdf(array $report, array $user): string
{
    $pdf = new PdfDocument('Sixpence report · ' . $report['range_label'], $user['username']);
    $width = PdfDocument::WIDTH - PDF_MARGIN * 2;
    $bottom = PdfDocument::HEIGHT - 60;
    $pdf->addPage();

    /* ---- Header ------------------------------------------------------- */
    pdf_brand($pdf, PDF_MARGIN, 40);
    $pdf->setFont(false, 8.5);
    $pdf->text(PDF_MARGIN + $width, 54, 'Prepared for ' . $user['username'], PDF_INK_3, 'right');

    $pdf->setFont(true, 22);
    $pdf->text(PDF_MARGIN, 104, 'Income & spending report');
    $pdf->setFont(false, 10.5);
    $pdf->text(PDF_MARGIN, 122, $report['range_label'] . '  ·  ' . plural($report['span_days'], 'day') . '  ·  compared with the previous ' . plural($report['span_days'], 'day'), PDF_INK_2);

    /* ---- Summary cards ------------------------------------------------ */
    $y = 142;
    $gap = 8;
    $card_w = ($width - $gap * 3) / 4;
    $change = static function (?int $pct, bool $higher_is_good): array {
        if ($pct === null) {
            return ['No earlier data', PDF_INK_3];
        }
        if ($pct === 0) {
            return ['Same as before', PDF_INK_3];
        }
        $good = ($pct > 0) === $higher_is_good;
        return [($pct > 0 ? '+' : '-') . abs($pct) . '% vs previous', $good ? PDF_IN : PDF_NEG];
    };
    $cards = [
        ['Income', pdf_money($report['total_income']), ...$change(percent_change($report['total_income'], $report['prev_income']), true)],
        ['Spending', pdf_money($report['total_spent']), ...$change(percent_change($report['total_spent'], $report['prev_spent']), false)],
        ['Net', pdf_money($report['net']), $report['net'] >= 0 ? 'Kept after spending' : 'Spent more than came in', PDF_INK_3],
        ['Savings rate', $report['savings_rate'] === null ? '-' : $report['savings_rate'] . '%', $report['savings_rate'] === null ? 'No income' : 'Share of income kept', PDF_INK_3],
    ];
    foreach ($cards as $i => [$label, $value, $note, $note_color]) {
        $x = PDF_MARGIN + $i * ($card_w + $gap);
        $pdf->rect($x, $y, $card_w, 64, '#ffffff', PDF_LINE, 0.75, 6);
        $pdf->setFont(false, 8.5);
        $pdf->text($x + 10, $y + 17, $label, PDF_INK_3);
        $pdf->setFont(true, 14);
        $pdf->text($x + 10, $y + 37, $pdf->fit($value, $card_w - 20), $report['net'] < 0 && $label === 'Net' ? PDF_NEG : PDF_INK);
        $pdf->setFont(false, 7.5);
        $pdf->text($x + 10, $y + 53, $pdf->fit($note, $card_w - 20), $note_color);
    }

    /* ---- Income vs spending bars --------------------------------------- */
    $y = 238;
    pdf_section_title($pdf, $y, 'Income and spending', 'Per ' . $report['granularity']);
    pdf_legend($pdf, PDF_MARGIN + $width, $y - 4, [['Income', PDF_IN], ['Spending', PDF_OUT]]);
    $max = max(1.0, ...array_map(static fn($b) => max($b['in'], $b['out']), $report['buckets']));
    [$plot_x, $plot_y, $plot_w, $plot_h] = [PDF_MARGIN + 44, $y + 20, $width - 44, 130];
    $ticks = pdf_ticks(0, $max);
    pdf_axes($pdf, $plot_x, $plot_y, $plot_w, $plot_h, $ticks);

    $count = count($report['buckets']);
    $slot = $plot_w / max(1, $count);
    $bar = min(9.0, $slot * 0.34);
    $scale = static fn(float $v) => $plot_h * ($v - $ticks[0]) / (end($ticks) - $ticks[0]);
    $label_every = (int) ceil($count / 12);
    foreach ($report['buckets'] as $i => $bucket) {
        $center = $plot_x + $slot * ($i + 0.5);
        foreach ([[$bucket['in'], PDF_IN, -$bar - 0.6], [$bucket['out'], PDF_OUT, 0.6]] as [$value, $color, $offset]) {
            $h = $scale($value);
            if ($h > 0.2) {
                $pdf->rect($center + $offset, $plot_y + $plot_h - $h, $bar, $h, $color, null, 0, min(1.5, $bar / 3));
            }
        }
        if ($i % $label_every === 0) {
            $pdf->setFont(false, 7);
            $pdf->text($center, $plot_y + $plot_h + 12, $bucket['label'], PDF_INK_3, 'center');
        }
    }

    /* ---- Running balance line --------------------------------------------- */
    $y = 420;
    pdf_section_title($pdf, $y, 'Running balance', 'Income minus spending, accumulated');
    $pdf->setFont(true, 9.5);
    $pdf->text(PDF_MARGIN + $width, $y - 4, 'Ends at ' . pdf_money($report['net'], 'always'), $report['net'] < 0 ? PDF_NEG : PDF_IN, 'right');
    $series = $report['running_series'];
    $ticks = pdf_ticks(min(0.0, ...$series), max(0.0, ...$series));
    [$plot_x, $plot_y, $plot_w, $plot_h] = [PDF_MARGIN + 44, $y + 20, $width - 44, 110];
    pdf_axes($pdf, $plot_x, $plot_y, $plot_w, $plot_h, $ticks);
    $span = end($ticks) - $ticks[0];
    $to_y = static fn(float $v) => $plot_y + $plot_h - $plot_h * ($v - $ticks[0]) / $span;
    $step = count($series) > 1 ? $plot_w / (count($series) - 1) : 0;
    $points = [];
    foreach ($series as $i => $value) {
        $points[] = [$plot_x + $step * $i, $to_y($value)];
    }
    if (count($points) === 1) {
        $points[] = [$plot_x + $plot_w, $points[0][1]];
    }
    $zero = $to_y(0);
    $pdf->polygon([[$points[0][0], $zero], ...$points, [end($points)[0], $zero]], PDF_IN_SOFT);
    if ($ticks[0] < 0) {
        $pdf->line($plot_x, $zero, $plot_x + $plot_w, $zero, PDF_INK_3, 0.6);
    }
    $pdf->polyline($points, PDF_IN, 1.6);
    $first_label = $report['buckets'][0]['label'] ?? '';
    $last_label = end($report['buckets'])['label'] ?? '';
    $pdf->setFont(false, 7);
    $pdf->text($plot_x, $plot_y + $plot_h + 12, $first_label, PDF_INK_3);
    $pdf->text($plot_x + $plot_w, $plot_y + $plot_h + 12, $last_label, PDF_INK_3, 'right');

    /* ---- Tables --------------------------------------------------------- */
    $y = 590;
    $total_spent = max(0.01, $report['total_spent']);
    $category_rows = [];
    $top = $report['by_category'] ? reset($report['by_category'])['total'] : 1;
    foreach ($report['by_category'] as $name => $row) {
        $pct = percent_change($row['total'], $report['prev_by_category'][$name] ?? 0);
        $category_rows[] = [
            'cells' => [$name, pdf_money($row['total']), percent($row['total'], $total_spent) . '%', $pct === null ? 'New' : ($pct === 0 ? '0%' : ($pct > 0 ? '+' : '-') . abs($pct) . '%')],
            'colors' => [PDF_INK, PDF_INK, PDF_INK_2, $pct === null || $pct === 0 ? PDF_INK_3 : ($pct > 0 ? PDF_NEG : PDF_IN)],
            'bar' => [$row['total'] / $top, PDF_OUT],
        ];
    }
    $y = pdf_table($pdf, $y, $bottom, 'Spending by category', plural(count($report['by_category']), 'category', 'categories'), ['Category', 'Spent', 'Share', 'Change'], [0.46, 0.24, 0.13, 0.17], $category_rows, 'No spending in this range.');

    $source_rows = [];
    $top = $report['by_source'] ? reset($report['by_source'])['total'] : 1;
    foreach ($report['by_source'] as $name => $row) {
        $source_rows[] = [
            'cells' => [$name, plural($row['count'], 'entry', 'entries'), pdf_money($row['total']), percent($row['total'], max(0.01, $report['total_income'])) . '%'],
            'colors' => [PDF_INK, PDF_INK_3, PDF_INK, PDF_INK_2],
            'bar' => [$row['total'] / $top, PDF_IN],
        ];
    }
    $y = pdf_table($pdf, $y + 26, $bottom, 'Income by source', plural(count($report['by_source']), 'source'), ['Source', 'Entries', 'Received', 'Share'], [0.46, 0.17, 0.24, 0.13], $source_rows, 'No income in this range.');

    $largest_rows = [];
    foreach ($report['largest'] as $row) {
        $largest_rows[] = [
            'cells' => [fmt_date($row['date'], 'M j, Y'), $row['description'] ?: 'Expense', $row['name'] ?: 'Uncategorized', pdf_money($row['amount'])],
            'colors' => [PDF_INK_3, PDF_INK, PDF_INK_2, PDF_INK],
        ];
    }
    pdf_table($pdf, $y + 26, $bottom, 'Largest expenses', 'Top ' . count($report['largest']), ['Date', 'Description', 'Category', 'Amount'], [0.18, 0.40, 0.22, 0.20], $largest_rows, 'No expenses in this range.', ['left', 'left', 'left', 'right']);

    /* ---- Footer on every page ------------------------------------------ */
    $generated = 'Generated by Sixpence on ' . date('M j, Y \a\t g:i a');
    $pdf->eachPage(static function (int $page, int $total) use ($pdf, $width, $generated): void {
        $y = PdfDocument::HEIGHT - 30;
        $pdf->line(PDF_MARGIN, $y - 12, PDF_MARGIN + $width, $y - 12, PDF_LINE, 0.6);
        $pdf->setFont(false, 7.5);
        $pdf->text(PDF_MARGIN, $y, $generated, PDF_INK_3);
        $pdf->text(PDF_MARGIN + $width, $y, "Page $page of $total", PDF_INK_3, 'right');
    });

    return $pdf->output();
}

function pdf_brand(PdfDocument $pdf, float $x, float $y): void
{
    // "6d" - the pre-decimal notation for sixpence. Drawn as text because the
    // PDF writer has no arc primitive to curve the bowls with.
    $pdf->rect($x, $y, 20, 20, PDF_INK, null, 0, 5);
    $pdf->setFont(true, 11);
    $pdf->text($x + 10, $y + 14, '6d', '#f5f3ee', 'center');
    $pdf->setFont(true, 13);
    $pdf->text($x + 28, $y + 14.5, 'Sixpence');
}

function pdf_section_title(PdfDocument $pdf, float $y, string $title, string $subtitle): void
{
    $pdf->setFont(true, 12);
    $pdf->text(PDF_MARGIN, $y, $title);
    $pdf->setFont(false, 8.5);
    $pdf->text(PDF_MARGIN + $pdf->textWidth($title, true, 12) + 8, $y, $subtitle, PDF_INK_3);
}

/** @param array<array{0: string, 1: string}> $items [label, color] */
function pdf_legend(PdfDocument $pdf, float $right, float $y, array $items): void
{
    $pdf->setFont(false, 8.5);
    $x = $right;
    foreach (array_reverse($items) as [$label, $color]) {
        $x -= $pdf->textWidth($label);
        $pdf->text($x, $y + 4, $label, PDF_INK_2);
        $x -= 12;
        $pdf->rect($x, $y - 3.5, 8, 8, $color, null, 0, 2);
        $x -= 14;
    }
}

/** Round, evenly spaced axis ticks covering [min, max]. */
function pdf_ticks(float $min, float $max, int $target = 4): array
{
    if ($max - $min < 0.01) {
        $max = $min + 1;
    }
    $raw = ($max - $min) / $target;
    $magnitude = 10 ** floor(log10($raw));
    $step = $magnitude * match (true) {
        $raw / $magnitude <= 1 => 1,
        $raw / $magnitude <= 2 => 2,
        $raw / $magnitude <= 2.5 => 2.5,
        $raw / $magnitude <= 5 => 5,
        default => 10,
    };
    $ticks = [];
    for ($v = floor($min / $step) * $step; $v < $max + $step * 0.999; $v += $step) {
        $ticks[] = round($v, 6);
    }
    return count($ticks) >= 2 ? $ticks : [$min, $max];
}

function pdf_axes(PdfDocument $pdf, float $x, float $y, float $w, float $h, array $ticks): void
{
    $min = $ticks[0];
    $span = end($ticks) - $min;
    $pdf->setFont(false, 7);
    foreach ($ticks as $tick) {
        $ty = $y + $h - $h * ($tick - $min) / $span;
        $pdf->line($x, $ty, $x + $w, $ty, PDF_GRID, 0.6);
        $pdf->text($x - 6, $ty + 2.5, pdf_money_short($tick), PDF_INK_3, 'right');
    }
}

/**
 * A simple table with an optional proportion bar under the first column. Continues on new pages.
 *
 * @param string[] $headers
 * @param float[] $columns fractions of the content width
 * @param string[] $aligns 'left' or 'right' per column
 * @param array<array{cells: string[], colors: string[], bar?: array{0: float, 1: string}}> $rows
 * @return float the y position after the table
 */
function pdf_table(PdfDocument $pdf, float $y, float $bottom, string $title, string $subtitle, array $headers, array $columns, array $rows, string $empty, array $aligns = ['left', 'right', 'right', 'right']): float
{
    $width = PdfDocument::WIDTH - PDF_MARGIN * 2;
    $row_h = 24;

    $draw_header = static function (float $y) use ($pdf, $headers, $columns, $width, $aligns): float {
        $x = PDF_MARGIN;
        $pdf->setFont(true, 7.5);
        foreach ($headers as $i => $header) {
            $col_w = $columns[$i] * $width;
            $right = ($aligns[$i] ?? 'right') === 'right';
            $pdf->text($right ? $x + $col_w : $x, $y, strtoupper($header), PDF_INK_3, $right ? 'right' : 'left');
            $x += $col_w;
        }
        $pdf->line(PDF_MARGIN, $y + 6, PDF_MARGIN + $width, $y + 6, PDF_LINE, 0.6);
        return $y + 6;
    };

    if ($y + 60 > $bottom) {
        $pdf->addPage();
        $y = 56;
    }
    pdf_section_title($pdf, $y, $title, $subtitle);
    $y = $draw_header($y + 20);

    if (!$rows) {
        $pdf->setFont(false, 9);
        $pdf->text(PDF_MARGIN, $y + 18, $empty, PDF_INK_3);
        return $y + 26;
    }

    foreach ($rows as $row) {
        if ($y + $row_h > $bottom) {
            $pdf->addPage();
            pdf_section_title($pdf, 56, $title, 'continued');
            $y = $draw_header(76);
        }
        $x = PDF_MARGIN;
        $has_bar = isset($row['bar']);
        foreach ($row['cells'] as $i => $cell) {
            $col_w = $columns[$i] * $width;
            $pdf->setFont(false, 9);
            $baseline = $y + ($has_bar && $i === 0 ? 13 : 16);
            $text = $pdf->fit($cell, $col_w - 10);
            $right = ($aligns[$i] ?? 'right') === 'right';
            $pdf->text($right ? $x + $col_w : $x, $baseline, $text, $row['colors'][$i] ?? PDF_INK, $right ? 'right' : 'left');
            if ($has_bar && $i === 0) {
                $bar_w = $col_w - 24;
                $pdf->rect($x, $y + 17, $bar_w, 3, PDF_SUNKEN, null, 0, 1.5);
                $pdf->rect($x, $y + 17, max(1.5, $bar_w * min(1, $row['bar'][0])), 3, $row['bar'][1], null, 0, 1.5);
            }
            $x += $col_w;
        }
        $y += $row_h;
        $pdf->line(PDF_MARGIN, $y, PDF_MARGIN + $width, $y, PDF_GRID, 0.5);
    }
    return $y;
}

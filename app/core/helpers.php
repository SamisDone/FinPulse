<?php
/**
 * General helpers: output escaping, redirects and flash messages,
 * form state, validation, money and date formatting.
 */
defined('FINPULSE') || exit;

/* ---------------------------------------------------------------------------
 * Output & requests
 * ------------------------------------------------------------------------ */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/** Redirect (303) to an app route. */
function redirect(string $route, array $query = [], string $fragment = ''): never
{
    redirect_to_url(url($route, $query, $fragment));
}

/**
 * Redirect to a URL that came from the request (a form's "return" field, the page someone
 * wanted before signing in). Only same-app paths are followed; anything else goes to the overview.
 */
function redirect_to_url(string $target): never
{
    $base = base_path() . '/';
    $safe = str_starts_with($target, $base)
        && !str_starts_with($target, '//')
        && !preg_match('~[\r\n\\\\]|^[a-z][a-z0-9+.-]*:~i', $target);
    header('Location: ' . ($safe ? $target : url('dashboard')), true, 303);
    exit;
}

/* ---------------------------------------------------------------------------
 * Flash messages & form state (Post/Redirect/Get)
 * ------------------------------------------------------------------------ */

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/** Remember submitted values and field errors for the next request. */
function keep_form(array $errors, array $old): void
{
    unset($old['_token'], $old['password'], $old['password_confirmation'], $old['current_password']);
    $_SESSION['_form'] = ['errors' => $errors, 'old' => $old];
}

function form_state(): array
{
    static $state = null;
    if ($state === null) {
        $state = $_SESSION['_form'] ?? ['errors' => [], 'old' => []];
        unset($_SESSION['_form']);
    }
    return $state;
}

function old(string $key, mixed $default = ''): string
{
    $old = form_state()['old'];
    return array_key_exists($key, $old) && is_scalar($old[$key]) ? (string) $old[$key] : (string) $default;
}

function has_old(): bool
{
    return form_state()['old'] !== [];
}

function error_for(string $key): ?string
{
    return form_state()['errors'][$key] ?? null;
}

/** Attributes + message markup for a field with a possible validation error. */
function invalid_attr(string $key): string
{
    return error_for($key) ? ' aria-invalid="true" aria-describedby="' . e($key) . '-error"' : '';
}

function field_error(string $key): string
{
    $message = error_for($key);
    if (!$message) {
        return '';
    }
    return '<p class="field-error" id="' . e($key) . '-error">' . icon('alert', 'icon icon-sm') . e($message) . '</p>';
}

/* ---------------------------------------------------------------------------
 * Validation
 * ------------------------------------------------------------------------ */

/** Parse a user-entered amount like "1,234.50". Returns null when invalid or not positive. */
function parse_amount(string $raw, bool $allow_zero = false): ?float
{
    $clean = str_replace([',', ' '], '', $raw);
    if ($clean === '' || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $clean)) {
        return null;
    }
    $value = round((float) $clean, 2);
    if ($value > 9999999999.99 || (!$allow_zero && $value <= 0)) {
        return null;
    }
    return $value;
}

function is_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function str_limit(string $value, int $max): string
{
    return mb_substr(trim($value), 0, $max);
}

/* ---------------------------------------------------------------------------
 * Money
 * ------------------------------------------------------------------------ */

function currencies(): array
{
    return [
        'USD' => ['$', 'US dollar'],
        'EUR' => ['€', 'Euro'],
        'GBP' => ['£', 'British pound'],
        'BDT' => ['৳', 'Bangladeshi taka'],
        'INR' => ['₹', 'Indian rupee'],
        'PKR' => ['Rs', 'Pakistani rupee'],
        'JPY' => ['¥', 'Japanese yen'],
        'CAD' => ['CA$', 'Canadian dollar'],
        'AUD' => ['A$', 'Australian dollar'],
        'SGD' => ['S$', 'Singapore dollar'],
        'AED' => ['AED', 'UAE dirham'],
        'CHF' => ['CHF', 'Swiss franc'],
    ];
}

/** Format money in a specific currency, e.g. when a background job works through several users. */
function use_currency(?string $code): void
{
    $GLOBALS['finpulse_currency'] = $code;
}

function currency_code(): string
{
    $code = $GLOBALS['finpulse_currency'] ?? (current_user()['currency'] ?? 'USD');
    return isset(currencies()[$code]) ? $code : 'USD';
}

function currency_symbol(): string
{
    return currencies()[currency_code()][0];
}

/**
 * Format an amount in the user's currency.
 * $sign: 'auto' shows a minus for negatives, 'always' adds + or −, 'none' shows the absolute value.
 */
function money(float|int|string|null $amount, string $sign = 'auto', int $decimals = 2): string
{
    $amount = (float) $amount;
    $symbol = currency_symbol();
    $spaced = strlen($symbol) > 1 && ctype_alpha($symbol[0]);
    $formatted = ($spaced ? $symbol . ' ' : $symbol) . number_format(abs($amount), $decimals);

    $prefix = '';
    if ($sign === 'always') {
        $prefix = $amount < 0 ? '−' : '+';
    } elseif ($sign === 'auto' && $amount < 0) {
        $prefix = '−';
    }
    return $prefix . $formatted;
}

/** Large display figure: the currency symbol is wrapped so it can be set in a lighter tone. */
function money_figure(float $amount): string
{
    $sign = $amount < 0 ? '−' : '';
    return $sign . '<span class="currency">' . e(currency_symbol()) . '</span>' . number_format(abs($amount), 2);
}

function percent(float $part, float $whole): int
{
    return $whole > 0 ? (int) round($part / $whole * 100) : 0;
}

/* ---------------------------------------------------------------------------
 * Dates
 * ------------------------------------------------------------------------ */

function today(): DateTimeImmutable
{
    return new DateTimeImmutable('today');
}

/** @return array{0:string,1:string} first and last day (Y-m-d) of the month containing $date */
function month_bounds(DateTimeImmutable $date): array
{
    return [$date->modify('first day of this month')->format('Y-m-d'), $date->modify('last day of this month')->format('Y-m-d')];
}

function fmt_date(string $ymd, string $format = 'M j, Y'): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    return $date ? $date->format($format) : $ymd;
}

/** "Today", "Yesterday", "Tomorrow", or "Mon, Sep 14" (with the year when it isn't this year). */
function day_label(string $ymd): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$date) {
        return $ymd;
    }
    $today = today();
    return match ($date->format('Y-m-d')) {
        $today->format('Y-m-d') => 'Today',
        $today->modify('-1 day')->format('Y-m-d') => 'Yesterday',
        $today->modify('+1 day')->format('Y-m-d') => 'Tomorrow',
        default => $date->format($date->format('Y') === $today->format('Y') ? 'D, M j' : 'D, M j, Y'),
    };
}

function days_between(string $from, string $to): int
{
    return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
}

/** "just now", "5 min ago", "3 h ago", "Yesterday", "Sep 14". */
function time_ago(string $datetime): string
{
    $then = new DateTimeImmutable($datetime);
    $seconds = time() - $then->getTimestamp();
    return match (true) {
        $seconds < 60 => 'just now',
        $seconds < 3600 => intdiv($seconds, 60) . ' min ago',
        $seconds < 86400 && $then->format('Y-m-d') === date('Y-m-d') => intdiv($seconds, 3600) . ' h ago',
        $then->format('Y-m-d') === date('Y-m-d', strtotime('-1 day')) => 'Yesterday',
        $then->format('Y') === date('Y') => $then->format('M j'),
        default => $then->format('M j, Y'),
    };
}

/** Neutralise spreadsheet formulas in a CSV cell (=, +, -, @ prefixes), leaving numbers untouched. */
function csv_safe(mixed $value): string
{
    $value = (string) $value;
    return preg_match('/^[=+\-@\t\r]/', $value) && !is_numeric($value) ? "'" . $value : $value;
}

function initials(string $name): string
{
    return mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: '?';
}

function plural(int $count, string $singular, ?string $plural = null): string
{
    return number_format($count) . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
}

/** Pass data to JavaScript safely inside a <script type="application/json"> tag. */
function json_script(string $id, mixed $data): string
{
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    return '<script type="application/json" id="' . e($id) . '">' . $json . '</script>';
}

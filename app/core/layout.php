<?php
/**
 * Page chrome: document head, app shell (sidebar + topbar), auth shell,
 * and small shared view components.
 */
defined('FINPULSE') || exit;

function page_open(string $title, array $opts = []): void
{
    $description = $opts['description'] ?? 'FinPulse is a personal finance tracker for income, spending, budgets and savings goals.';
    $full_title = $title === 'FinPulse' ? $title : $title . ' · FinPulse';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($full_title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f5f3ee" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#12110f" media="(prefers-color-scheme: dark)">
<meta property="og:title" content="<?= e($full_title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:type" content="website">
<?php if (!empty($opts['noindex'])): ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preload" href="<?= e(base_path()) ?>/assets/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<script src="<?= e(asset('js/theme.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body<?= !empty($opts['body_class']) ? ' class="' . e($opts['body_class']) . '"' : '' ?>>
<a class="skip-link" href="#main">Skip to content</a>
    <?php
}

function page_close(array $scripts = []): void
{
    render_toasts();
    render_confirm_dialog();
    foreach ($scripts as $src) {
        echo '<script src="' . e($src) . '" defer></script>' . "\n";
    }
    echo "</body>\n</html>\n";
}

/** Scripts for pages with charts. */
function chart_scripts(): array
{
    return [asset('vendor/chart-4.4.1.umd.min.js'), asset('js/charts.js')];
}

/* ---------------------------------------------------------------------------
 * Signed-in app shell
 * ------------------------------------------------------------------------ */

function nav_items(): array
{
    return [
        'dashboard' => ['Overview', 'overview'],
        'income' => ['Income', 'income'],
        'expenses' => ['Expenses', 'expense'],
        'budgets' => ['Budgets', 'budget'],
        'savings' => ['Savings', 'savings'],
        'reports' => ['Reports', 'reports'],
    ];
}

function app_open(string $title): void
{
    $user = current_user();
    $active = current_route();
    $unread = unread_notification_count($user['id']);
    $unread_label = $unread > 99 ? '99+' : (string) $unread;
    page_open($title, ['noindex' => true]);
    ?>
<div class="shell" data-shell>
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="spread">
      <a class="brand" href="<?= e(url('dashboard')) ?>"><?= brand_mark() ?><span>FinPulse</span></a>
      <button class="icon-btn hide-desktop" type="button" data-nav-close aria-label="Close menu"><?= icon('close') ?></button>
    </div>

    <nav class="nav">
      <?php foreach (nav_items() as $route => [$label, $glyph]): ?>
      <a href="<?= e(url($route)) ?>"<?= $route === $active ? ' aria-current="page"' : '' ?>><?= icon($glyph) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <nav class="nav" aria-label="Account">
        <a href="<?= e(url('notifications')) ?>"<?= $active === 'notifications' ? ' aria-current="page"' : '' ?>>
          <?= icon('bell') ?><span>Notifications</span>
          <?php if ($unread): ?><span class="badge" aria-label="<?= $unread ?> unread"><?= e($unread_label) ?></span><?php endif; ?>
        </a>
        <a href="<?= e(url('settings')) ?>"<?= $active === 'settings' ? ' aria-current="page"' : '' ?>><?= icon('settings') ?><span>Settings</span></a>
        <button class="nav-button" type="button" data-theme-toggle><?= icon('monitor') ?><span data-theme-label>Theme: system</span></button>
      </nav>
      <div class="user-chip">
        <span class="avatar" aria-hidden="true"><?= e(initials($user['username'])) ?></span>
        <div class="who">
          <strong class="truncate"><?= e($user['username']) ?></strong>
          <span class="truncate"><?= e($user['email']) ?></span>
        </div>
        <form method="post" action="<?= e(url('logout')) ?>">
          <?= csrf_field() ?>
          <button class="icon-btn" type="submit" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></button>
        </form>
      </div>
    </div>
  </aside>
  <div class="scrim" data-nav-close></div>

  <div class="shell-main">
    <header class="topbar">
      <button class="icon-btn" type="button" data-nav-open aria-controls="sidebar" aria-expanded="false" aria-label="Open menu"><?= icon('menu') ?></button>
      <a class="brand" href="<?= e(url('dashboard')) ?>"><?= brand_mark() ?><span>FinPulse</span></a>
      <div class="topbar-actions">
        <a class="icon-btn has-dot<?= $unread ? ' is-on' : '' ?>" href="<?= e(url('notifications')) ?>" aria-label="Notifications<?= $unread ? ", $unread unread" : '' ?>"><?= icon('bell') ?></a>
        <a class="icon-btn" href="<?= e(url('expenses', [], 'add')) ?>" aria-label="Add expense"><?= icon('plus') ?></a>
      </div>
    </header>
    <main class="page" id="main" tabindex="-1">
    <?php
}

function app_close(array $scripts = []): void
{
    echo "    </main>\n  </div>\n</div>\n";
    page_close($scripts);
}

function page_header(string $title, string $subtitle = '', string $actions = ''): void
{
    ?>
<header class="page-header">
  <div>
    <h1 class="page-title"><?= e($title) ?></h1>
    <?php if ($subtitle !== ''): ?><p class="page-sub"><?= $subtitle ?></p><?php endif; ?>
  </div>
  <?php if ($actions !== ''): ?><div class="cluster"><?= $actions ?></div><?php endif; ?>
</header>
    <?php
}

/* ---------------------------------------------------------------------------
 * Auth shell (sign in, create account, password reset)
 * ------------------------------------------------------------------------ */

function auth_open(string $title): void
{
    page_open($title);
    ?>
<div class="auth">
  <div class="auth-main">
    <a class="brand" href="<?= e(url()) ?>"><?= brand_mark() ?><span>FinPulse</span></a>
    <main class="auth-form" id="main" tabindex="-1">
    <?php
}

function auth_close(): void
{
    ?>
    </main>
    <p class="auth-foot">No bank logins, no ads, no trackers.</p>
  </div>
  <aside class="auth-aside" aria-hidden="true">
    <div class="sheet">
      <div class="sheet-head"><span><?= date('F') ?></span><span>Sample</span></div>
      <div class="sheet-row"><span>Rent</span><span>$1,450.00</span></div>
      <div class="sheet-row"><span>Groceries</span><span>$386.42</span></div>
      <div class="sheet-row"><span>Transit pass</span><span>$89.00</span></div>
      <div class="sheet-row"><span>Left to spend</span><span class="sheet-good">$612.58</span></div>
    </div>
    <p class="auth-quote">Know where this month went, and decide where the next one goes.</p>
    <p class="auth-cite">Income, spending, budgets and goals in one quiet ledger.</p>
  </aside>
</div>
    <?php
    page_close();
}

/* ---------------------------------------------------------------------------
 * Shared components
 * ------------------------------------------------------------------------ */

function render_toasts(): void
{
    $flashes = take_flashes();
    echo '<div class="toasts" role="status" aria-live="polite" data-toasts>';
    foreach ($flashes as $flash) {
        $type = in_array($flash['type'], ['success', 'error', 'info'], true) ? $flash['type'] : 'info';
        $glyph = ['success' => 'check-circle', 'error' => 'alert', 'info' => 'info'][$type];
        echo '<div class="toast" data-type="' . $type . '">' . icon($glyph) . '<p>' . e($flash['message']) . '</p>'
            . '<button type="button" data-toast-close aria-label="Dismiss">' . icon('close', 'icon icon-sm') . '</button></div>';
    }
    echo '</div>';
}

function render_confirm_dialog(): void
{
    ?>
<dialog class="dialog" id="confirm-dialog" aria-labelledby="confirm-title">
  <form method="dialog">
    <div class="dialog-body">
      <h2 class="dialog-title" id="confirm-title">Are you sure?</h2>
      <p class="dialog-text" data-confirm-text></p>
    </div>
    <div class="dialog-actions">
      <button class="btn" value="cancel">Cancel</button>
      <button class="btn btn-danger" value="confirm" data-confirm-button>Delete</button>
    </div>
  </form>
</dialog>
    <?php
}

function empty_state(string $glyph, string $title, string $text, string $action = ''): string
{
    return '<div class="empty"><div class="empty-art">' . icon($glyph, 'icon icon-lg') . '</div>'
        . '<p class="empty-title">' . e($title) . '</p><p>' . e($text) . '</p>' . $action . '</div>';
}

function pagination(int $page, int $pages, int $total, int $per_page): string
{
    if ($pages <= 1) {
        return '';
    }
    $from = ($page - 1) * $per_page + 1;
    $to = min($total, $page * $per_page);

    $numbers = array_unique(array_filter([1, $page - 1, $page, $page + 1, $pages], static fn($n) => $n >= 1 && $n <= $pages));
    sort($numbers);

    $html = '<nav class="pagination" aria-label="Pagination"><span class="small faint num">' . number_format($from) . '–' . number_format($to) . ' of ' . number_format($total) . '</span><div class="pages">';
    if ($page > 1) {
        $html .= '<a href="' . e(url_with(['page' => $page - 1])) . '" aria-label="Previous page">' . icon('chevron-left', 'icon icon-sm') . '</a>';
    }
    $previous = 0;
    foreach ($numbers as $n) {
        if ($n - $previous > 1) {
            $html .= '<span class="gap">…</span>';
        }
        $html .= $n === $page
            ? '<span class="cur" aria-current="page">' . $n . '</span>'
            : '<a href="' . e(url_with(['page' => $n])) . '">' . $n . '</a>';
        $previous = $n;
    }
    if ($page < $pages) {
        $html .= '<a href="' . e(url_with(['page' => $page + 1])) . '" aria-label="Next page">' . icon('chevron-right', 'icon icon-sm') . '</a>';
    }
    return $html . '</div></nav>';
}

function render_error_page(int $code, string $title, string $message): void
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    $signed_in = current_user_safe();
    page_open($title, ['noindex' => true]);
    ?>
<main class="notfound" id="main">
  <div>
    <p class="code"><?= $code ?></p>
    <h1 class="notfound-title"><?= e($title) ?></h1>
    <p class="notfound-text"><?= e($message) ?></p>
    <div class="notfound-actions">
      <a class="btn btn-primary" href="<?= e(url($signed_in ? 'dashboard' : '')) ?>"><?= icon('arrow-right') ?>Go to <?= $signed_in ? 'overview' : 'home page' ?></a>
    </div>
  </div>
</main>
    <?php
    page_close();
}

/** current_user() that never throws, for error pages where the database may be the problem. */
function current_user_safe(): bool
{
    try {
        return current_user() !== null;
    } catch (Throwable) {
        return false;
    }
}

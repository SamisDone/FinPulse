<?php
/**
 * Inline SVG icon set. One stroke weight (set in CSS), 24px grid, currentColor.
 */
defined('FINPULSE') || exit;

function icon(string $name, string $class = 'icon'): string
{
    static $paths = [
        'overview' => '<rect x="3.5" y="3.5" width="7" height="9" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="5" rx="1.5"/><rect x="13.5" y="11.5" width="7" height="9" rx="1.5"/><rect x="3.5" y="15.5" width="7" height="5" rx="1.5"/>',
        'income' => '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M4.5 19.5h15"/>',
        'expense' => '<path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M4.5 19.5h15"/>',
        'budget' => '<path d="M12 3.5a8.5 8.5 0 1 0 8.5 8.5H12Z"/><path d="M15 3.8A8.5 8.5 0 0 1 20.2 9H15Z"/>',
        'savings' => '<path d="M4 9.5h16v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5Z"/><path d="M3.5 6.5a1.5 1.5 0 0 1 1.5-1.5h14a1.5 1.5 0 0 1 1.5 1.5v3h-17Z"/><path d="M10 13.5h4"/>',
        'reports' => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8 16v-4"/><path d="M12 16V8"/><path d="M16 16v-6"/>',
        'settings' => '<path d="M4 7h9"/><path d="M17 7h3"/><circle cx="15" cy="7" r="2"/><path d="M4 17h3"/><path d="M11 17h9"/><circle cx="9" cy="17" r="2"/>',
        'logout' => '<path d="M14 4.5h3.5A1.5 1.5 0 0 1 19 6v12a1.5 1.5 0 0 1-1.5 1.5H14"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h9"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
        'edit' => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/>',
        'trash' => '<path d="M4.5 7h15"/><path d="M9.5 7V5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v2"/><path d="M6.5 7l.8 11.6A1.5 1.5 0 0 0 8.8 20h6.4a1.5 1.5 0 0 0 1.5-1.4L17.5 7"/>',
        'close' => '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>',
        'menu' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h10"/>',
        'chevron-left' => '<path d="m14.5 6-6 6 6 6"/>',
        'chevron-right' => '<path d="m9.5 6 6 6-6 6"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
        'download' => '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 20h14"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.2 2.4 2.4 4.6-4.8"/>',
        'alert' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5"/><path d="M12 16h.01"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        'sun' => '<circle cx="12" cy="12" r="3.5"/><path d="M12 3v2"/><path d="M12 19v2"/><path d="m5.6 5.6 1.4 1.4"/><path d="m17 17 1.4 1.4"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="m5.6 18.4 1.4-1.4"/><path d="m17 7 1.4-1.4"/>',
        'moon' => '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10Z"/>',
        'monitor' => '<rect x="3.5" y="4.5" width="17" height="11.5" rx="1.5"/><path d="M9 20h6"/><path d="M12 16v4"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.8"/>',
        'eye-off' => '<path d="M4 4l16 16"/><path d="M10 5.7A9.8 9.8 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-2.9 3.6"/><path d="M6.4 7.4C3.9 9.1 2.5 12 2.5 12S6 18.5 12 18.5a9.3 9.3 0 0 0 4.6-1.2"/><path d="M10 10a2.8 2.8 0 0 0 4 4"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17"/><path d="M8 3v4"/><path d="M16 3v4"/>',
        'target' => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r=".8"/>',
        'lock' => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
        'server' => '<rect x="4" y="4" width="16" height="7" rx="1.5"/><rect x="4" y="13" width="16" height="7" rx="1.5"/><path d="M8 7.5h.01"/><path d="M8 16.5h.01"/>',
        'file' => '<path d="M14 3.5H7A1.5 1.5 0 0 0 5.5 5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V8Z"/><path d="M14 3.5V8h4.5"/><path d="M9 13h6"/><path d="M9 16.5h4"/>',
        'wallet' => '<path d="M18.5 7.5V6A1.5 1.5 0 0 0 17 4.5H5.5a2 2 0 0 0 0 4H19a1.5 1.5 0 0 1 1.5 1.5v8A1.5 1.5 0 0 1 19 19.5H5.5a2 2 0 0 1-2-2v-11"/><path d="M16.5 14h.01"/>',
        'pulse' => '<path d="M3 12h4l2.5-6 5 12 2.5-6h4"/>',
        'more' => '<circle cx="6" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="18" cy="12" r="1"/>',
        'trend-up' => '<path d="m4 16 5-5 3.5 3.5L20 7"/><path d="M14.5 7H20v5.5"/>',
        'bell' => '<path d="M6.5 16.5V11a5.5 5.5 0 0 1 11 0v5.5l1.5 2h-14Z"/><path d="M10 20.5a2 2 0 0 0 4 0"/>',
        'repeat' => '<path d="M4.5 11V9.5a3 3 0 0 1 3-3h11"/><path d="m15.5 3.5 3 3-3 3"/><path d="M19.5 13v1.5a3 3 0 0 1-3 3h-11"/><path d="m8.5 20.5-3-3 3-3"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="m4 7 8 6 8-6"/>',
        'trend-down' => '<path d="m4 8 5 5 3.5-3.5L20 17"/><path d="M14.5 17H20v-5.5"/>',
    ];

    $body = $paths[$name] ?? $paths['info'];
    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/** The FinPulse mark: a ledger tile with a pulse line. */
function brand_mark(string $class = 'brand-mark'): string
{
    return '<svg class="' . htmlspecialchars($class, ENT_QUOTES) . '" viewBox="0 0 32 32" aria-hidden="true">'
        . '<rect width="32" height="32" rx="8" fill="var(--ink)"/>'
        . '<path d="M6.5 17h5l2.5-6.5 4 11 2.5-6.5h5" fill="none" stroke="var(--bg)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="25.5" cy="15" r="1.9" fill="#3fae84"/>'
        . '</svg>';
}

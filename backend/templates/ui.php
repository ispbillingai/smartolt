<?php
// Small pieces of markup every page shares, in the same Tailwind style as the rest of the panel.

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf'] ?? '') . '">';
}

/** Page title block at the top of <main>. */
function page_heading(string $title, string $subtitle = '', string $right = ''): string
{
    return '<div class="mb-6 flex flex-col md:flex-row md:items-end md:justify-between gap-3"><div>'
        . '<h2 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white tracking-tight">' . e($title) . '</h2>'
        . ($subtitle !== '' ? '<p class="text-sm text-slate-500 dark:text-slate-400 mt-1">' . e($subtitle) . '</p>' : '')
        . '</div>' . $right . '</div>';
}

/** One stat card. $tone: brand, emerald, rose, amber, violet, teal. */
function stat_card(string $label, $value, string $tone, string $iconPath): string
{
    return '<div class="relative overflow-hidden bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-5">'
        . '<div class="relative flex items-center gap-3">'
        . '<div class="w-10 h-10 rounded-xl bg-' . $tone . '-50 dark:bg-' . $tone . '-900/40 text-' . $tone . '-600 dark:text-' . $tone . '-300 flex items-center justify-center flex-shrink-0">'
        . '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $iconPath . '"></path></svg></div>'
        . '<div><p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">' . e($label) . '</p>'
        . '<h3 class="text-2xl font-bold text-slate-900 dark:text-white mt-0.5">' . e($value) . '</h3></div></div></div>';
}

/** Rounded pill. $tone: emerald, rose, amber, slate, brand. */
function badge(string $text, string $tone): string
{
    return '<span class="inline-flex items-center rounded-full bg-' . $tone . '-50 dark:bg-' . $tone . '-900/40 text-' . $tone . '-700 dark:text-' . $tone . '-300 px-2 py-0.5 text-xs font-medium">' . e($text) . '</span>';
}

function run_state_badge(string $state): string
{
    $s = strtolower($state);
    if ($s === 'online') return badge('Online', 'emerald');
    if ($s === '') return badge('Not read yet', 'slate');
    return badge(ucfirst($s), 'rose');
}

/**
 * Light level the ONU receives. Huawei GPON: about -8 to -25 dBm is healthy, -25 to -27 is weak,
 * below -27 (or above -8, too hot) causes drops.
 */
function signal_html($rx): string
{
    if ($rx === null || $rx === '') return '<span class="text-slate-400">--</span>';
    $v = (float)$rx;
    $tone = ($v < -27 || $v > -8) ? 'text-rose-600 dark:text-rose-400' : ($v < -25 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400');
    return '<span class="font-semibold whitespace-nowrap ' . $tone . '">' . number_format($v, 2) . ' dBm</span>';
}

function flash_messages(): string
{
    $html = '';
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        $tone = $type === 'error'
            ? 'bg-rose-50 dark:bg-rose-900/30 border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300'
            : 'bg-emerald-50 dark:bg-emerald-900/30 border-emerald-200 dark:border-emerald-800/50 text-emerald-700 dark:text-emerald-300';
        $html .= '<div class="mb-4 p-3 border rounded-lg text-sm ' . $tone . '">' . e($msg) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/** Card wrapper for a table or form. */
function panel_open(string $title, string $right = ''): string
{
    return '<div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft mb-8"><div class="p-6">'
        . '<div class="flex flex-col md:flex-row md:justify-between md:items-center mb-4 gap-3">'
        . '<h3 class="text-base font-semibold text-slate-800 dark:text-slate-100">' . e($title) . '</h3>' . $right . '</div>';
}

function panel_close(): string
{
    return '</div></div>';
}

const UI_INPUT = 'block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition text-sm';
const UI_LABEL = 'block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5';
const UI_BTN = 'inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-brand-500';
const UI_BTN_PRIMARY = UI_BTN . ' bg-brand-600 hover:bg-brand-700 text-white';
const UI_BTN_GREEN = UI_BTN . ' bg-emerald-600 hover:bg-emerald-700 text-white';
const UI_BTN_AMBER = UI_BTN . ' bg-amber-500 hover:bg-amber-600 text-white';
const UI_BTN_RED = UI_BTN . ' bg-rose-600 hover:bg-rose-700 text-white';
const UI_BTN_GHOST = UI_BTN . ' border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700';
const UI_TH = 'px-4 py-3 font-medium';
const UI_TD = 'px-4 py-3';
const UI_THEAD = 'text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide bg-slate-50 dark:bg-slate-800/40 border-y border-slate-200 dark:border-slate-700';

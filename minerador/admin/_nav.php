<?php

declare(strict_types=1);

/**
 * Sufixo URL para preservar `scope=mine` (“Só token do admin”) entre páginas do admin de config.
 */
function minerador_admin_nav_scope_suffix(): string
{
    if (minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine') {
        return '?scope=mine';
    }

    return '';
}

/**
 * @param array<string, string|int|null> $extra
 */
function minerador_admin_nav_qs(array $extra): string
{
    $base = $_GET;
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($base[$k]);
        } else {
            $base[$k] = $v;
        }
    }

    return http_build_query($base);
}

function minerador_admin_nav_export_href(): string
{
    $keys = ['scope', 'search_id', 'fq', 'nome', 'cidade', 'estado', 'pais', 'date_from', 'date_to', 'tem_site', 'order', 'dir'];
    $p = [];
    foreach ($keys as $k) {
        if (!isset($_GET[$k])) {
            continue;
        }
        $v = $_GET[$k];
        if (is_array($v)) {
            continue;
        }
        $s = trim((string) $v);
        if ($s === '') {
            continue;
        }
        $p[$k] = $s;
    }

    return $p === [] ? 'export.php' : ('export.php?' . http_build_query($p));
}

function minerador_admin_current_script(): string
{
    $s = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($s === '') {
        return 'index.php';
    }

    return $s;
}

/**
 * Estilos partilhados: cabeçalho, botões base, toggle Todos / Só token do admin.
 */
function minerador_admin_header_css(): string
{
    return <<<'CSS'
    .admin-header { padding:16px 20px; background:#111827; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
    .admin-header-left { display:flex; align-items:center; flex-wrap:wrap; gap:12px 16px; min-width:0; }
    .admin-header-left h1 { margin:0; font-size:18px; font-weight:700; }
    .admin-header-nav { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
    .admin-subtitle { flex-basis:100%; width:100%; margin:0; padding-top:2px; font-size:13px; color:#9ca3af; font-weight:400; text-align:center; }
    .scope-toggle { display:inline-flex; border:1px solid #374151; border-radius:8px; overflow:hidden; font-size:13px; font-weight:600; }
    .scope-toggle a { padding:7px 12px; text-decoration:none; color:#e5e7eb; background:#1f2937; }
    .scope-toggle a:hover { background:#374151; }
    .scope-toggle a.active { background:#2563eb; color:#fff; }
    input[type="date"] { color-scheme: light; }
    input[type="date"]::-webkit-calendar-picker-indicator {
      cursor: pointer;
      filter: invert(1) brightness(1.15);
      opacity: 1;
    }
CSS;
}

function minerador_admin_render_scope_toggle(): void
{
    if (!minerador_admin_is_config_admin()) {
        return;
    }
    $script = minerador_admin_current_script();
    $isMine = (string) ($_GET['scope'] ?? '') === 'mine';
    $qAll = minerador_admin_nav_qs(['scope' => null]);
    $qMine = minerador_admin_nav_qs(['scope' => 'mine']);
    $hrefAll = $script . ($qAll !== '' ? '?' . $qAll : '');
    $hrefMine = $script . ($qMine !== '' ? '?' . $qMine : '');

    $tip = 'Todos: todas as buscas e leads no servidor. Só token do admin: apenas leads e buscas enviados com o minerador_token do config.php (owner_key cfg).';
    echo '<div class="scope-toggle" role="group" aria-label="Filtro: todos os dados ou só token do admin" title="' . h($tip) . '">';
    echo '<a class="' . ($isMine ? '' : 'active') . '" href="' . h($hrefAll) . '" title="Sem filtro por token">Todos</a>';
    echo '<a class="' . ($isMine ? 'active' : '') . '" href="' . h($hrefMine) . '" title="Apenas dados do token do config.php">Só token do admin</a>';
    echo '</div>';
}

/**
 * @param array{leads_clear_search?: bool} $opts
 */
function minerador_admin_render_header_nav(array $opts = []): void
{
    $leadsClearSearch = !empty($opts['leads_clear_search']);
    $ss = minerador_admin_nav_scope_suffix();
    $script = minerador_admin_current_script();

    echo '<a class="btn secondary" href="index.php' . h($ss) . '">Home</a>';
    echo '<a class="btn secondary" href="leads.php' . h($ss) . '">Leads</a>';

    if ($leadsClearSearch && $script === 'leads.php') {
        echo '<a class="btn secondary" href="leads.php?' . h(minerador_admin_nav_qs(['search_id' => null])) . '">All leads</a>';
    }

    echo '<a class="btn secondary" href="searches.php' . h($ss) . '">Searches</a>';

    if (minerador_admin_is_config_admin()) {
        echo '<a class="btn secondary" href="users.php' . h($ss) . '">Users</a>';
    }

    echo '<a class="btn secondary" href="settings.php' . h($ss) . '">Config</a>';
    echo '<a class="btn secondary" href="logout.php">Sair</a>';
}

/**
 * @param array{leads_clear_search?: bool} $opts
 */
function minerador_admin_render_page_header(?string $subtitleHtml, array $navOpts = []): void
{
    echo '<header class="admin-header">';
    echo '<div class="admin-header-left">';
    echo '<h1>Minerador.pt</h1>';
    minerador_admin_render_scope_toggle();
    echo '</div>';
    echo '<nav class="admin-header-nav">';
    minerador_admin_render_header_nav($navOpts);
    echo '</nav>';
    if ($subtitleHtml !== null && $subtitleHtml !== '') {
        echo '<p class="admin-subtitle">' . $subtitleHtml . '</p>';
    }
    echo '</header>';
}

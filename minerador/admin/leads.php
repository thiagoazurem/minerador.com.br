<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

$pdo = minerador_pdo();
$csrf = minerador_csrf_token();
$qualificacaoWebsiteRules = minerador_settings_get_qualificacao_website_rules($pdo);

$allowedReturnKeys = ['scope', 'search_id', 'fq', 'nome', 'cidade', 'estado', 'pais', 'date_from', 'date_to', 'tem_site', 'order', 'dir', 'page'];
$returnQsForPostActions = [];
foreach ($allowedReturnKeys as $k) {
    if (!isset($_GET[$k]) || is_array($_GET[$k])) {
        continue;
    }
    $s = trim((string) $_GET[$k]);
    if ($s === '') {
        continue;
    }
    $returnQsForPostActions[$k] = $s;
}
$returnQsHiddenValue = $returnQsForPostActions === [] ? '' : http_build_query($returnQsForPostActions);

$searchIdFilter = (int) ($_GET['search_id'] ?? 0);
$searchInfo = null;
if ($searchIdFilter > 0) {
    $st = $pdo->prepare('SELECT * FROM minerador_searches WHERE id = ? LIMIT 1');
    $st->execute([$searchIdFilter]);
    $searchInfo = $st->fetch();
    if (!$searchInfo || !minerador_admin_can_manage_search($searchInfo)) {
        unset($_GET['search_id']);
        $searchIdFilter = 0;
        $searchInfo = null;
    }
}

[$whereSql, $filterParams] = minerador_admin_leads_filter_sql_from_query($_GET);
[$scopeSql, $scopeParams] = minerador_admin_lead_scope_sql(true);
$whereSql .= $scopeSql;
$filterParams = array_merge($filterParams, $scopeParams);

$order = (string) ($_GET['order'] ?? 'qualificacao');
$allowed = ['id', 'nome', 'coletado_em', 'cidade', 'estado', 'pais', 'pagina', 'nota', 'rate_num', 'query_text', 'website', 'qualificacao', 'search_id'];
if (!in_array($order, $allowed, true)) {
    $order = 'qualificacao';
}
$dir = strtolower((string) ($_GET['dir'] ?? 'desc'));
$dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$off = ($page - 1) * $per;

try {
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM minerador_leads l LEFT JOIN minerador_searches s ON s.id = l.search_id WHERE 1=1 ' . $whereSql);
    $countStmt->execute($filterParams);
    $totalRows = (int) $countStmt->fetchColumn();
} catch (Throwable $e) {
    error_log(sprintf(
        '[minerador leads.php count] %s @ %s:%d | where=%s',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $whereSql
    ));
    http_response_code(500);
    exit('Erro ao carregar a listagem de leads.');
}
$totalPages = max(1, (int) ceil($totalRows / $per));
if ($page > $totalPages) {
    $page = $totalPages;
    $off = ($page - 1) * $per;
}

try {
    $sqlList = 'SELECT l.*, s.localizacao AS search_localizacao FROM minerador_leads l LEFT JOIN minerador_searches s ON s.id = l.search_id WHERE 1=1 ' . $whereSql . ' ORDER BY l.`' . str_replace('`', '', $order) . '` ' . $dirSql . ' LIMIT ' . $per . ' OFFSET ' . (int) $off;
    $stmt = $pdo->prepare($sqlList);
    $stmt->execute($filterParams);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log(sprintf(
        '[minerador leads.php list] %s @ %s:%d | where=%s',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $whereSql
    ));
    http_response_code(500);
    exit('Erro ao carregar a listagem de leads.');
}

/**
 * @param mixed $phones JSON string, array (PDO em alguns drivers) ou vazio.
 */
function first_phone($phones): string
{
    if ($phones === null || $phones === '') {
        return '';
    }
    if (is_array($phones)) {
        $flat = array_values(array_filter(array_map('strval', $phones)));

        return $flat === [] ? '' : (string) $flat[0];
    }
    if (!is_string($phones)) {
        return '';
    }
    $arr = json_decode($phones, true);
    if (!is_array($arr) || $arr === []) {
        return '';
    }

    return (string) ($arr[0] ?? '');
}

function minerador_lead_row_border_style(?string $qual): string
{
    $q = $qual === null ? '' : trim($qual);
    if ($q === '') {
        return 'border-left:0 solid transparent';
    }
    $map = [
        'max' => '5px solid red',
        'alto' => '5px solid orange',
        'medio' => '5px solid yellow',
        'baixo' => '5px solid black',
    ];

    return isset($map[$q]) ? ('border-left:' . $map[$q]) : 'border-left:0 solid transparent';
}

function qs(array $extra): string
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

/**
 * @param 'asc'|'desc' $dirNorm lowercase asc or desc
 * @param string $thExtraClass classes extra no th (ex.: col-busca)
 * @param string $accessibleSortLabel texto para title/aria dos links de ordenação (obrigatório se $labelIsRawHtml)
 */
function minerador_leads_sort_th(string $col, string $label, string $order, string $dirNorm, string $thExtraClass = '', bool $labelIsRawHtml = false, string $accessibleSortLabel = ''): string
{
    $ascActive = $order === $col && $dirNorm === 'asc';
    $descActive = $order === $col && $dirNorm === 'desc';
    $up = '?' . qs(['order' => $col, 'dir' => 'asc', 'page' => 1]);
    $dn = '?' . qs(['order' => $col, 'dir' => 'desc', 'page' => 1]);
    $upClass = 'sort-dir' . ($ascActive ? ' is-active' : '');
    $dnClass = 'sort-dir' . ($descActive ? ' is-active' : '');
    $ariaSort = 'none';
    if ($ascActive) {
        $ariaSort = 'ascending';
    } elseif ($descActive) {
        $ariaSort = 'descending';
    }

    $chevUp = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" /></svg>';
    $chevDn = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" /></svg>';

    $sortLinkLabel = $accessibleSortLabel !== '' ? $accessibleSortLabel : $label;
    $labelHtml = $labelIsRawHtml ? $label : h($label);
    $thClass = 'th-sort' . ($thExtraClass !== '' ? ' ' . $thExtraClass : '');
    $thAria = $labelIsRawHtml && $sortLinkLabel !== '' ? ' aria-label="' . h($sortLinkLabel) . '"' : '';

    return '<th class="' . h($thClass) . '" scope="col" aria-sort="' . h($ariaSort) . '"' . $thAria . '>'
        . '<span class="th-sort-inner">'
        . '<span class="th-sort-label">' . $labelHtml . '</span>'
        . '<span class="sort-arrows">'
        . '<a class="' . h($upClass) . '" href="' . h($up) . '" title="' . h($sortLinkLabel . ' — ascendente') . '" aria-label="' . h($sortLinkLabel . ', ascendente') . '">' . $chevUp . '</a>'
        . '<a class="' . h($dnClass) . '" href="' . h($dn) . '" title="' . h($sortLinkLabel . ' — descendente') . '" aria-label="' . h($sortLinkLabel . ', descendente') . '">' . $chevDn . '</a>'
        . '</span></span></th>';
}

/** Exibe nota (uma casa decimal) e contagem de avaliações na listagem de leads. */
function minerador_leads_format_nota_avaliacoes(mixed $notaRaw, mixed $rateRaw): string
{
    $notaStr = trim((string) ($notaRaw ?? ''));
    $rateStr = trim((string) ($rateRaw ?? ''));
    $hasNota = $notaStr !== '' && is_numeric($notaStr);
    $hasRate = $rateStr !== '' && is_numeric($rateStr);
    if (!$hasNota && !$hasRate) {
        return '';
    }
    if ($hasNota) {
        $n = number_format((float) $notaStr, 1, '.', '');

        return $hasRate ? ($n . ' (' . (string) (int) (float) $rateStr . ')') : $n;
    }

    return '— (' . (string) (int) (float) $rateStr . ')';
}

$leadsScopeMine = minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine';

$flashDeleted = isset($_GET['deleted']);
$flashFilterDeleted = isset($_GET['filter_deleted']) ? max(0, (int) $_GET['filter_deleted']) : null;
$flashFilterNoTerms = isset($_GET['filter_delete_no_terms']);
$flashFilterNone = isset($_GET['filter_delete_none']);
$flashFilterErr = isset($_GET['filter_delete_err']);
$flashFilterForbidden = isset($_GET['filter_delete_forbidden']);

$subtitleHtml = '';
if ($searchInfo) {
    $subtitleHtml = ' / busca ' . h((string) $searchInfo['slug']) . ' — ' . h((string) $searchInfo['keyword']) . ' ' . h((string) $searchInfo['localizacao']);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Leads — Minerador.pt</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    a { color:#93c5fd; }
    <?= minerador_admin_header_css() ?>
    main { padding:18px 20px 40px; max-width:1400px; margin:0 auto; }
    form.filters { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:14px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:10px; align-items:end; margin-bottom:12px; }
    .filter-actions { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:16px; }
    .filter-actions-inner { display:flex; flex-wrap:wrap; align-items:center; gap:10px; grid-column:1 / -1; }
    label span { display:block; font-size:12px; color:#9ca3af; margin-bottom:4px; }
    input, select { width:100%; padding:8px 10px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; }
    .btn { display:inline-block; padding:9px 14px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; }
    .btn.secondary { background:#374151; }
    .btn.danger { background:#991b1b; }
    .btn.danger:hover { background:#b91c1c; }
    table { width:100%; border-collapse:collapse; font-size:13px; background:#111827; border:1px solid #1f2937; border-radius:10px; overflow:hidden; }
    th, td { padding:10px 8px; border-bottom:1px solid #1f2937;border-left:1px solid #1f2937; text-align:left; vertical-align:top; }
    th { background:#0f172a; font-size:12px; color:#9ca3af; white-space:nowrap; }
    th.th-sort { vertical-align:middle; }
    th.th-sort .th-sort-inner { display:flex; align-items:center; justify-content:flex-start; gap:8px; flex-wrap:nowrap; width:100%; box-sizing:border-box; }
    th.th-sort .th-sort-label { flex:1; min-width:0; font-size:12px; color:#9ca3af; font-weight:600; }
    th.th-sort .sort-arrows { display:flex; flex-direction:column; flex-shrink:0; gap:0; line-height:0; }
    th.th-sort .sort-dir { display:flex; align-items:center; justify-content:center; color:#64748b; padding:2px; border-radius:4px; line-height:0; text-decoration:none; }
    th.th-sort .sort-dir:hover { color:#e5e7eb; background:#1e293b; }
    th.th-sort .sort-dir.is-active { color:#fbbf24; }
    th.th-sort .sort-dir svg { width:12px; height:12px; display:block; }
    th.th-sort-icon-only .th-star-icon { width:18px; height:18px; display:block; color:#fbbf24; }
    tr:last-child td { border-bottom:none; }
    .pager { margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .muted { color:#9ca3af; font-size:12px; }
    .nowrap { white-space:nowrap; }
    th.col-busca,
    td.col-busca {
      max-width:150px;
      box-sizing:border-box;
      white-space:normal;
      word-break:break-word;
      overflow-wrap:anywhere;
    }
    th.col-busca.th-sort .th-sort-inner { flex-wrap:wrap; align-items:flex-start; }
    th.col-busca .th-sort-label { white-space:normal; min-width:0; }
    .col-busca a { white-space:normal; word-break:break-word; overflow-wrap:anywhere; }
    .row-actions { display:flex; align-items:center; gap:6px; flex-wrap:nowrap; }
    .row-actions a.icon-btn,
    .row-actions button.icon-btn {
      display:inline-flex; align-items:center; justify-content:center;
      width:34px; height:34px; padding:0; border-radius:8px;
      border:1px solid #374151; background:#0f172a; color:#e5e7eb;
      text-decoration:none; cursor:pointer; flex-shrink:0;
    }
    .row-actions a.icon-btn:hover,
    .row-actions button.icon-btn:hover { background:#1e293b; border-color:#64748b; }
    .row-actions button.icon-btn { font:inherit; }
    .row-actions .icon-btn.danger { border-color:#7f1d1d; color:#fecaca; background:#1c1917; }
    .row-actions .icon-btn.danger:hover { background:#450a0a; }
    .row-actions form { display:inline; margin:0; padding:0; }
    .row-actions svg { width:18px; height:18px; }
    .site-cell a.icon-btn {
      display:inline-flex; align-items:center; justify-content:center;
      width:34px; height:34px; padding:0; border-radius:8px;
      border:1px solid #374151; background:#0f172a; color:#e5e7eb;
      text-decoration:none; cursor:pointer; flex-shrink:0;
    }
    .site-cell a.icon-btn:hover { background:#1e293b; border-color:#64748b; }
    .site-cell svg { width:18px; height:18px; }
    .flash { background:#065f46; color:#d1fae5; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
    .flash.warn { background:#78350f; color:#ffedd5; }
    .filter-delete-dialog { max-width:480px; width:calc(100vw - 32px); border:none; border-radius:12px; padding:0; background:#111827; color:#e5e7eb; box-shadow:0 20px 50px rgba(0,0,0,.5); }
    .filter-delete-dialog::backdrop { background:rgba(0,0,0,.65); }
    .filter-delete-dialog-inner { padding:18px 20px 20px; }
    .filter-delete-dialog h3 { margin:0 0 10px; font-size:17px; color:#fecaca; }
    .filter-delete-dialog textarea { width:100%; min-height:100px; margin-top:8px; padding:8px 10px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; font:inherit; box-sizing:border-box; }
    .filter-delete-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:16px; align-items:center; }
  </style>
</head>
<body>
  <?php minerador_admin_render_page_header($subtitleHtml, ['leads_clear_search' => $searchInfo !== null]); ?>
  <main>
    <?php if ($flashDeleted): ?><div class="flash">Lead excluído.</div><?php endif; ?>
    <?php if ($flashFilterDeleted !== null): ?><div class="flash">Foram excluídos <?= h((string) $flashFilterDeleted) ?> lead(s) que coincidem com os termos e o filtro atual.</div><?php endif; ?>
    <?php if ($flashFilterNoTerms): ?><div class="flash warn">Indique pelo menos um termo (separados por vírgula).</div><?php endif; ?>
    <?php if ($flashFilterNone): ?><div class="flash warn">Nenhum lead corresponde ao filtro atual e aos termos indicados.</div><?php endif; ?>
    <?php if ($flashFilterErr): ?><div class="flash warn">Erro ao excluir leads. Nada foi alterado ou confirme o estado na base de dados.</div><?php endif; ?>
    <?php if ($flashFilterForbidden): ?><div class="flash warn">Sem permissão para excluir leads desta busca.</div><?php endif; ?>
    <form class="filters" id="leadsFiltersForm" method="get">
      <?php if ($searchIdFilter > 0): ?>
        <input type="hidden" name="search_id" value="<?= h((string) $searchIdFilter) ?>" />
      <?php endif; ?>
      <?php if (minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine'): ?>
        <input type="hidden" name="scope" value="mine" />
      <?php endif; ?>
      <label><span>Query (contém)</span><input name="fq" value="<?= h((string) ($_GET['fq'] ?? '')) ?>" /></label>
      <label><span>Nome (contém)</span><input name="nome" value="<?= h((string) ($_GET['nome'] ?? '')) ?>" /></label>
      <label><span>Cidade</span><input name="cidade" value="<?= h((string) ($_GET['cidade'] ?? '')) ?>" /></label>
      <label><span>Estado</span><input name="estado" maxlength="64" value="<?= h((string) ($_GET['estado'] ?? '')) ?>" /></label>
      <label><span>País</span><input name="pais" maxlength="128" value="<?= h((string) ($_GET['pais'] ?? '')) ?>" /></label>
      <label><span>Data de (coletado_em)</span><input type="date" name="date_from" value="<?= h((string) ($_GET['date_from'] ?? '')) ?>" /></label>
      <label><span>Data até</span><input type="date" name="date_to" value="<?= h((string) ($_GET['date_to'] ?? '')) ?>" /></label>
      <label><span>Tem site</span>
        <select name="tem_site">
          <?php $ts = (string) ($_GET['tem_site'] ?? ''); ?>
          <option value="" <?= $ts === '' ? 'selected' : '' ?>>qualquer</option>
          <option value="sim" <?= $ts === 'sim' ? 'selected' : '' ?>>sim</option>
          <option value="nao" <?= $ts === 'nao' ? 'selected' : '' ?>>não</option>
        </select>
      </label>
      <label><span>Ordenar</span>
        <select name="order">
          <?php foreach ($allowed as $col): ?>
            <option value="<?= h($col) ?>" <?= $order === $col ? 'selected' : '' ?>><?= h($col) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span>Direção</span>
        <select name="dir">
          <option value="desc" <?= $dirSql === 'DESC' ? 'selected' : '' ?>>desc</option>
          <option value="asc" <?= $dirSql === 'ASC' ? 'selected' : '' ?>>asc</option>
        </select>
      </label>
      <div class="filter-actions-inner">
        <button class="btn" type="submit">Filtrar</button>
      </div>
    </form>
    <div class="filter-actions">
      <button class="btn secondary" type="button" onclick="window.location.href = '<?= h(minerador_admin_nav_export_href()) ?>';">Exportar CSV</button>
      <button class="btn danger" type="button" id="openFilterDeleteDialog">Excluir leads por filtro</button>
    </div>

    <dialog id="filterDeleteDialog" class="filter-delete-dialog" aria-labelledby="filterDeleteDialogTitle">
      <div class="filter-delete-dialog-inner">
        <h3 id="filterDeleteDialogTitle">Excluir leads por filtro</h3>
        <p class="muted" style="font-size:14px;">Serão eliminados permanentemente os leads que cumprem os <strong>filtros atuais</strong> (em todas as páginas desta listagem) e cujo texto contém <strong>qualquer</strong> um dos termos abaixo. A comparação não distingue maiúsculas/minúsculas.</p>
        <form method="post" action="leads_delete_by_filter.php" onsubmit="return confirm('Confirmar exclusão permanente destes leads?');">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
          <input type="hidden" name="return_qs" value="<?= h($returnQsHiddenValue) ?>" />
          <?php if ($leadsScopeMine): ?>
            <input type="hidden" name="scope" value="mine" />
          <?php endif; ?>
          <label for="filterDeleteTerms" style="display:block; font-size:12px; color:#9ca3af; margin-top:12px;">Termos (separados por vírgula)</label>
          <textarea id="filterDeleteTerms" name="terms" spellcheck="false" placeholder="ex.: spam, teste, loja x"></textarea>
          <div class="filter-delete-actions">
            <button type="submit" class="btn danger">Buscar e excluir</button>
            <button type="button" class="btn secondary" id="closeFilterDeleteDialog">Cancelar</button>
          </div>
        </form>
      </div>
    </dialog>
    <script>
      (function () {
        var dlg = document.getElementById('filterDeleteDialog');
        var openBtn = document.getElementById('openFilterDeleteDialog');
        var closeBtn = document.getElementById('closeFilterDeleteDialog');
        var ta = document.getElementById('filterDeleteTerms');
        if (!dlg || !openBtn) return;
        openBtn.addEventListener('click', function () {
          if (ta) ta.value = '';
          dlg.showModal();
          if (ta) ta.focus();
        });
        if (closeBtn) closeBtn.addEventListener('click', function () { dlg.close(); });
      })();
    </script>

    <?php
    $thStarNotaLabel = '<svg xmlns="http://www.w3.org/2000/svg" class="th-star-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" /></svg>';
    ?>

    <p class="muted">Mostrando <?= h((string) count($rows)) ?> de <?= h((string) $totalRows) ?> (página <?= h((string) $page) ?> / <?= h((string) $totalPages) ?>)</p>

    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <?= minerador_leads_sort_th('id', '#', $order, $dir) ?>
            <?php if (!$searchInfo): ?>
              <?= minerador_leads_sort_th('search_id', 'Busca', $order, $dir, 'col-busca') ?>
            <?php endif; ?>
            <?= minerador_leads_sort_th('nome', 'Nome', $order, $dir) ?>
            <?= minerador_leads_sort_th('nota', $thStarNotaLabel, $order, $dir, 'th-sort-icon-only col-nota-avaliacoes', true, 'Nota (média) e número de avaliações') ?>
            <?= minerador_leads_sort_th('cidade', 'Local', $order, $dir) ?>
            <th>Phone</th>
            <?= minerador_leads_sort_th('website', 'Site', $order, $dir) ?>
            <?= minerador_leads_sort_th('qualificacao', '🔥', $order, $dir) ?>
            <?= minerador_leads_sort_th('coletado_em', 'Coletado', $order, $dir) ?>
            <th class="nowrap" style="text-align:center">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
            $tel = first_phone($r['phones'] ?? null);
            $site = (string) ($r['website'] ?? '');
            $maskWebsiteInList = $site !== '' && minerador_website_matches_qualificacao_substring($site, $qualificacaoWebsiteRules);
            $qr = $r['qualificacao'] ?? null;
            $qcell = $qr === null ? '' : trim((string) $qr);
            $rowBorder = minerador_lead_row_border_style($qcell === '' ? null : $qcell);
            ?>
            <tr style="<?= h($rowBorder) ?>">
              <td class="nowrap"><?= h((string) $r['id']) ?></td>
              <?php if (!$searchInfo): ?>
                <td class="col-busca">
                  <?php $sid = (int) ($r['search_id'] ?? 0); ?>
                  <?php if ($sid > 0): ?>
                    <a href="?<?= h(qs(['search_id' => $sid])) ?>"><?= h(trim((string) ($r['keyword'] ?? ''))) ?></a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td><?= h((string) $r['nome']) ?></td>
              <td class="nowrap"><?php
                $na = minerador_leads_format_nota_avaliacoes($r['nota'] ?? null, $r['rate_num'] ?? null);
                echo $na !== '' ? h($na) : '<span class="muted">—</span>';
                ?></td>
              <td>
                  <?= h(implode(' / ', array_filter([
                      !empty($r['cidade'])
                          ? (string) $r['cidade'] . (!empty($r['pais']) ? ' (' . (string) $r['pais'] . ')' : '')
                          : null,
                      $r['estado'] ?? null
                  ]))) ?>
              </td>
              <td><?= h($tel) ?></td>
              <td class="site-cell nowrap" style="text-align:center"><?php if ($site !== ''): ?>
                <?php if ($maskWebsiteInList): ?>
                  <span class="muted" title="URL oculto na lista por corresponder à qualificação automática; abra a ficha do lead para ver o site completo.">?</span>
                <?php else: ?>
                <a class="icon-btn" href="<?= h($site) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($site) ?>" aria-label="Abrir website">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                </a>
                <?php endif; ?>
              <?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <td class="nowrap"><?php if ($qcell !== ''): ?><?= h($qcell) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <td><?php
                $cem = trim((string) ($r['coletado_em'] ?? ''));
                if ($cem === ''): ?><span class="muted">—</span><?php else:
                  $cemParts = preg_split('/\s+/', $cem, 2);
                  if (count($cemParts) === 2): ?><?= h($cemParts[0]) ?><br /><?= h($cemParts[1]) ?><?php else: ?><?= h($cem) ?><?php endif;
                endif; ?></td>
              <td class="nowrap">
                <?php
                $lid = (int) $r['id'];
                $sidRow = (int) ($r['search_id'] ?? 0);
                ?>
                <div class="row-actions">
                  <a class="icon-btn" href="lead.php?id=<?= h((string) $lid) ?><?= $leadsScopeMine ? '&scope=mine' : '' ?>#lead-edit" title="Editar lead" aria-label="Editar lead">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                  </a>
                  <form method="post" action="lead_delete.php" onsubmit="return confirm('Excluir este lead permanentemente?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                    <input type="hidden" name="id" value="<?= h((string) $lid) ?>" />
                    <?php if ($leadsScopeMine): ?>
                      <input type="hidden" name="scope" value="mine" />
                    <?php endif; ?>
                    <?php if ($sidRow > 0): ?>
                      <input type="hidden" name="back_search_id" value="<?= h((string) $sidRow) ?>" />
                    <?php endif; ?>
                    <button type="submit" class="icon-btn danger" title="Excluir lead" aria-label="Excluir lead">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3h-16.5m2.25 0h11.218m-11.218 0V18a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 18V6M12 9v9m-3-9v9m6-9v9" /></svg>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($rows === []): ?>
            <tr><td colspan="<?= $searchInfo ? 9 : 10 ?>">Nenhum registro.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pager">
      <?php if ($page > 1): ?>
        <a class="btn secondary" href="?<?= h(qs(['page' => $page - 1])) ?>">« Anterior</a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a class="btn secondary" href="?<?= h(qs(['page' => $page + 1])) ?>">Próxima »</a>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

$pdo = minerador_pdo();
$csrf = minerador_csrf_token();

function build_filters(): array
{
    $where = [];
    $params = [];

    $searchId = (int) ($_GET['search_id'] ?? 0);
    if ($searchId > 0) {
        $where[] = 'l.search_id = ?';
        $params[] = $searchId;
    }

    $fq = trim((string) ($_GET['fq'] ?? ''));
    if ($fq !== '') {
        $where[] = 'l.query_text LIKE ?';
        $params[] = '%' . $fq . '%';
    }

    $cidade = trim((string) ($_GET['cidade'] ?? ''));
    if ($cidade !== '') {
        $where[] = 'l.cidade LIKE ?';
        $params[] = '%' . $cidade . '%';
    }

    $estado = trim((string) ($_GET['estado'] ?? ''));
    if ($estado !== '') {
        $where[] = 'l.estado LIKE ?';
        $params[] = '%' . $estado . '%';
    }

    $pais = trim((string) ($_GET['pais'] ?? ''));
    if ($pais !== '') {
        $where[] = 'l.pais LIKE ?';
        $params[] = '%' . $pais . '%';
    }

    $nome = trim((string) ($_GET['nome'] ?? ''));
    if ($nome !== '') {
        $where[] = 'l.nome LIKE ?';
        $params[] = '%' . $nome . '%';
    }

    $df = trim((string) ($_GET['date_from'] ?? ''));
    if ($df !== '') {
        $where[] = 'DATE(l.coletado_em) >= ?';
        $params[] = $df;
    }
    $dt = trim((string) ($_GET['date_to'] ?? ''));
    if ($dt !== '') {
        $where[] = 'DATE(l.coletado_em) <= ?';
        $params[] = $dt;
    }

    $tem = trim((string) ($_GET['tem_site'] ?? ''));
    if ($tem === 'sim') {
        $where[] = '(l.website IS NOT NULL AND l.website <> \'\')';
    } elseif ($tem === 'nao') {
        $where[] = '(l.website IS NULL OR l.website = \'\')';
    }

    $sql = $where ? (' AND ' . implode(' AND ', $where)) : '';

    return [$sql, $params];
}

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

[$whereSql, $filterParams] = build_filters();
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
 */
function minerador_leads_sort_th(string $col, string $label, string $order, string $dirNorm): string
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

    return '<th class="th-sort" scope="col" aria-sort="' . h($ariaSort) . '">'
        . '<span class="th-sort-inner">'
        . '<span class="th-sort-label">' . h($label) . '</span>'
        . '<span class="sort-arrows">'
        . '<a class="' . h($upClass) . '" href="' . h($up) . '" title="' . h($label . ' — ascendente') . '" aria-label="' . h($label . ', ascendente') . '">' . $chevUp . '</a>'
        . '<a class="' . h($dnClass) . '" href="' . h($dn) . '" title="' . h($label . ' — descendente') . '" aria-label="' . h($label . ', descendente') . '">' . $chevDn . '</a>'
        . '</span></span></th>';
}

$leadsScopeMine = minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine';

$flashDeleted = isset($_GET['deleted']);

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
    form.filters { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:14px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:10px; align-items:end; margin-bottom:16px; }
    .filter-actions { display:flex; flex-wrap:wrap; align-items:center; gap:10px; grid-column:1 / -1; }
    label span { display:block; font-size:12px; color:#9ca3af; margin-bottom:4px; }
    input, select { width:100%; padding:8px 10px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; }
    .btn { display:inline-block; padding:9px 14px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; }
    .btn.secondary { background:#374151; }
    table { width:100%; border-collapse:collapse; font-size:13px; background:#111827; border:1px solid #1f2937; border-radius:10px; overflow:hidden; }
    th, td { padding:10px 8px; border-bottom:1px solid #1f2937; text-align:left; vertical-align:top; }
    th { background:#0f172a; font-size:12px; color:#9ca3af; white-space:nowrap; }
    th.th-sort { vertical-align:middle; }
    th.th-sort .th-sort-inner { display:flex; align-items:center; justify-content:flex-start; gap:8px; flex-wrap:nowrap; width:100%; box-sizing:border-box; }
    th.th-sort .th-sort-label { flex:1; min-width:0; font-size:12px; color:#9ca3af; font-weight:600; }
    th.th-sort .sort-arrows { display:flex; flex-direction:column; flex-shrink:0; gap:0; line-height:0; }
    th.th-sort .sort-dir { display:flex; align-items:center; justify-content:center; color:#64748b; padding:2px; border-radius:4px; line-height:0; text-decoration:none; }
    th.th-sort .sort-dir:hover { color:#e5e7eb; background:#1e293b; }
    th.th-sort .sort-dir.is-active { color:#fbbf24; }
    th.th-sort .sort-dir svg { width:12px; height:12px; display:block; }
    tr:last-child td { border-bottom:none; }
    .pager { margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .muted { color:#9ca3af; font-size:12px; }
    .nowrap { white-space:nowrap; }
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
  </style>
</head>
<body>
  <?php minerador_admin_render_page_header($subtitleHtml, ['leads_clear_search' => $searchInfo !== null]); ?>
  <main>
    <?php if ($flashDeleted): ?><div class="flash">Lead excluído.</div><?php endif; ?>
    <form class="filters" method="get">
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
      <div class="filter-actions">
        <button class="btn" type="submit">Filtrar</button>
        <button class="btn secondary" type="button" onclick="window.location.href = '<?= h(minerador_admin_nav_export_href()) ?>';">Exportar CSV</button>
      </div>
    </form>

    <p class="muted">Mostrando <?= h((string) count($rows)) ?> de <?= h((string) $totalRows) ?> (página <?= h((string) $page) ?> / <?= h((string) $totalPages) ?>)</p>

    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <?= minerador_leads_sort_th('id', 'ID', $order, $dir) ?>
            <?php if (!$searchInfo): ?>
              <?= minerador_leads_sort_th('search_id', 'Busca', $order, $dir) ?>
            <?php endif; ?>
            <?= minerador_leads_sort_th('nome', 'Nome', $order, $dir) ?>
            <?= minerador_leads_sort_th('nota', 'Nota', $order, $dir) ?>
            <?= minerador_leads_sort_th('rate_num', 'Aval.', $order, $dir) ?>
            <?= minerador_leads_sort_th('cidade', 'Local', $order, $dir) ?>
            <th>Telefone</th>
            <?= minerador_leads_sort_th('website', 'Site', $order, $dir) ?>
            <?= minerador_leads_sort_th('qualificacao', 'Qualif.', $order, $dir) ?>
            <?= minerador_leads_sort_th('coletado_em', 'Coletado', $order, $dir) ?>
            <th class="nowrap">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
            $tel = first_phone($r['phones'] ?? null);
            $site = (string) ($r['website'] ?? '');
            $qr = $r['qualificacao'] ?? null;
            $qcell = $qr === null ? '' : trim((string) $qr);
            $rowBorder = minerador_lead_row_border_style($qcell === '' ? null : $qcell);
            ?>
            <tr style="<?= h($rowBorder) ?>">
              <td class="nowrap"><?= h((string) $r['id']) ?></td>
              <?php if (!$searchInfo): ?>
                <td class="nowrap">
                  <?php $sid = (int) ($r['search_id'] ?? 0); ?>
                  <?php if ($sid > 0): ?>
                    <a href="?<?= h(qs(['search_id' => $sid])) ?>"><?= h(trim((string) ($r['keyword'] ?? '') . ' ' . (string) ($r['search_localizacao'] ?? ''))) ?></a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td><?= h((string) $r['nome']) ?></td>
              <td><?= h((string) ($r['nota'] ?? '')) ?></td>
              <td><?= h((string) ($r['rate_num'] ?? '')) ?></td>
              <td><?= h(trim(implode(' / ', array_filter([(string) $r['cidade'], (string) $r['estado'], (string) ($r['pais'] ?? '')])))) ?></td>
              <td><?= h($tel) ?></td>
              <td class="site-cell nowrap"><?php if ($site !== ''): ?>
                <a class="icon-btn" href="<?= h($site) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($site) ?>" aria-label="Abrir website">
                  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                </a>
              <?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <td class="nowrap"><?php if ($qcell !== ''): ?><?= h($qcell) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <td class="nowrap"><?= h((string) $r['coletado_em']) ?></td>
              <td class="nowrap">
                <?php
                $lid = (int) $r['id'];
                $sidRow = (int) ($r['search_id'] ?? 0);
                ?>
                <div class="row-actions">
                  <a class="icon-btn" href="lead.php?id=<?= h((string) $lid) ?><?= $leadsScopeMine ? '&scope=mine' : '' ?>" title="Ver lead" aria-label="Ver lead">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                  </a>
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
            <tr><td colspan="<?= $searchInfo ? 10 : 11 ?>">Nenhum registro.</td></tr>
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

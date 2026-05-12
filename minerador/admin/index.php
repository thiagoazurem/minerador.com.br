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
        $where[] = 'search_id = ?';
        $params[] = $searchId;
    }

    $fq = trim((string) ($_GET['fq'] ?? ''));
    if ($fq !== '') {
        $where[] = 'query_text LIKE ?';
        $params[] = '%' . $fq . '%';
    }

    $cidade = trim((string) ($_GET['cidade'] ?? ''));
    if ($cidade !== '') {
        $where[] = 'cidade LIKE ?';
        $params[] = '%' . $cidade . '%';
    }

    $uf = strtoupper(trim((string) ($_GET['uf'] ?? '')));
    if ($uf !== '' && strlen($uf) === 2) {
        $where[] = 'uf = ?';
        $params[] = $uf;
    }

    $nome = trim((string) ($_GET['nome'] ?? ''));
    if ($nome !== '') {
        $where[] = 'nome LIKE ?';
        $params[] = '%' . $nome . '%';
    }

    $df = trim((string) ($_GET['date_from'] ?? ''));
    if ($df !== '') {
        $where[] = 'DATE(coletado_em) >= ?';
        $params[] = $df;
    }
    $dt = trim((string) ($_GET['date_to'] ?? ''));
    if ($dt !== '') {
        $where[] = 'DATE(coletado_em) <= ?';
        $params[] = $dt;
    }

    $addweb = trim((string) ($_GET['addweb'] ?? ''));
    if ($addweb === 'sim' || $addweb === 'nao') {
        $where[] = 'addweb = ?';
        $params[] = $addweb;
    }

    $tem = trim((string) ($_GET['tem_site'] ?? ''));
    if ($tem === 'sim') {
        $where[] = '(website IS NOT NULL AND website <> \'\')';
    } elseif ($tem === 'nao') {
        $where[] = '(website IS NULL OR website = \'\')';
    }

    $sql = $where ? (' AND ' . implode(' AND ', $where)) : '';
    return [$sql, $params];
}

[$whereSql, $filterParams] = build_filters();

$searchIdFilter = (int) ($_GET['search_id'] ?? 0);
$searchInfo = null;
if ($searchIdFilter > 0) {
    $st = $pdo->prepare('SELECT * FROM minerador_searches WHERE id = ? LIMIT 1');
    $st->execute([$searchIdFilter]);
    $searchInfo = $st->fetch();
    if (!$searchInfo) {
        $searchIdFilter = 0;
    }
}

$kpiTotal = (int) $pdo->query('SELECT COUNT(*) FROM minerador_leads')->fetchColumn();
$kpiToday = (int) $pdo->query('SELECT COUNT(*) FROM minerador_leads WHERE DATE(created_at) = CURDATE()')->fetchColumn();
$kpiNoSite = (int) $pdo->query("SELECT COUNT(*) FROM minerador_leads WHERE website IS NULL OR website = ''")->fetchColumn();
$kpiAddweb = (int) $pdo->query("SELECT COUNT(*) FROM minerador_leads WHERE addweb = 'sim'")->fetchColumn();
$kpiQueries = (int) $pdo->query('SELECT COUNT(DISTINCT query_text) FROM minerador_leads')->fetchColumn();

$order = (string) ($_GET['order'] ?? 'coletado_em');
$allowed = ['id', 'nome', 'coletado_em', 'cidade', 'uf', 'pagina', 'nota', 'total_avaliacoes', 'query_text', 'website', 'addweb', 'search_id'];
if (!in_array($order, $allowed, true)) {
    $order = 'coletado_em';
}
$dir = strtolower((string) ($_GET['dir'] ?? 'desc'));
$dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$off = ($page - 1) * $per;

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM minerador_leads WHERE 1=1 ' . $whereSql);
$countStmt->execute($filterParams);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $per));
if ($page > $totalPages) {
    $page = $totalPages;
    $off = ($page - 1) * $per;
}

$sqlList = 'SELECT * FROM minerador_leads WHERE 1=1 ' . $whereSql . ' ORDER BY `' . str_replace('`', '', $order) . '` ' . $dirSql . ' LIMIT ' . $per . ' OFFSET ' . (int) $off;
$stmt = $pdo->prepare($sqlList);
$stmt->execute($filterParams);
$rows = $stmt->fetchAll();

function first_phone(?string $json): string
{
    if ($json === null || $json === '') {
        return '';
    }
    $arr = json_decode($json, true);
    if (!is_array($arr) || $arr === []) {
        return '';
    }
    return (string) ($arr[0] ?? '');
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

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Dashboard — Minerador</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    a { color:#93c5fd; }
    header { padding:16px 20px; background:#111827; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
    header h1 { margin:0; font-size:18px; }
    main { padding:18px 20px 40px; max-width:1400px; margin:0 auto; }
    .kpis { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:18px; }
    .kpi { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:12px 14px; }
    .kpi .v { font-size:22px; font-weight:700; }
    .kpi .l { font-size:12px; color:#9ca3af; margin-top:4px; }
    form.filters { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:14px; display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:10px; align-items:end; margin-bottom:16px; }
    label span { display:block; font-size:12px; color:#9ca3af; margin-bottom:4px; }
    input, select { width:100%; padding:8px 10px; border-radius:8px; border:1px solid #374151; background:#0b1220; color:#e5e7eb; }
    .btn { display:inline-block; padding:9px 14px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; }
    .btn.secondary { background:#374151; }
    table { width:100%; border-collapse:collapse; font-size:13px; background:#111827; border:1px solid #1f2937; border-radius:10px; overflow:hidden; }
    th, td { padding:10px 8px; border-bottom:1px solid #1f2937; text-align:left; vertical-align:top; }
    th { background:#0f172a; font-size:12px; color:#9ca3af; white-space:nowrap; }
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
  </style>
</head>
<body>
  <header>
    <h1>
      Minerador — Leads coletados
      <?php if ($searchInfo): ?>
        <span style="font-size:13px;color:#9ca3af;font-weight:400;">/ busca <?= h((string) $searchInfo['slug']) ?> — <?= h((string) $searchInfo['keyword']) ?> <?= h((string) $searchInfo['localizacao']) ?></span>
      <?php endif; ?>
    </h1>
    <div>
      <a class="btn secondary" href="searches.php">Buscas</a>
      <?php if ($searchInfo): ?>
        <a class="btn secondary" href="index.php">Todos os leads</a>
      <?php endif; ?>
      <a class="btn secondary" href="export.php?<?= h(qs(['page' => null])) ?>">Exportar CSV</a>
      <a class="btn secondary" href="logout.php">Sair</a>
    </div>
  </header>
  <main>
    <div class="kpis">
      <div class="kpi"><div class="v"><?= h((string) $kpiTotal) ?></div><div class="l">Total de leads</div></div>
      <div class="kpi"><div class="v"><?= h((string) $kpiToday) ?></div><div class="l">Novos hoje (created_at)</div></div>
      <div class="kpi"><div class="v"><?= h((string) $kpiNoSite) ?></div><div class="l">Sem website</div></div>
      <div class="kpi"><div class="v"><?= h((string) $kpiAddweb) ?></div><div class="l">addweb = sim</div></div>
      <div class="kpi"><div class="v"><?= h((string) $kpiQueries) ?></div><div class="l">Queries distintas</div></div>
    </div>

    <form class="filters" method="get">
      <?php if ($searchIdFilter > 0): ?>
        <input type="hidden" name="search_id" value="<?= h((string) $searchIdFilter) ?>" />
      <?php endif; ?>
      <label><span>Query (contém)</span><input name="fq" value="<?= h((string) ($_GET['fq'] ?? '')) ?>" /></label>
      <label><span>Nome (contém)</span><input name="nome" value="<?= h((string) ($_GET['nome'] ?? '')) ?>" /></label>
      <label><span>Cidade</span><input name="cidade" value="<?= h((string) ($_GET['cidade'] ?? '')) ?>" /></label>
      <label><span>UF</span><input name="uf" maxlength="2" value="<?= h((string) ($_GET['uf'] ?? '')) ?>" /></label>
      <label><span>Data de (coletado_em)</span><input type="date" name="date_from" value="<?= h((string) ($_GET['date_from'] ?? '')) ?>" /></label>
      <label><span>Data até</span><input type="date" name="date_to" value="<?= h((string) ($_GET['date_to'] ?? '')) ?>" /></label>
      <label><span>addweb</span>
        <select name="addweb">
          <?php $ad = (string) ($_GET['addweb'] ?? ''); ?>
          <option value="" <?= $ad === '' ? 'selected' : '' ?>>qualquer</option>
          <option value="sim" <?= $ad === 'sim' ? 'selected' : '' ?>>sim</option>
          <option value="nao" <?= $ad === 'nao' ? 'selected' : '' ?>>não</option>
        </select>
      </label>
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
      <div><button class="btn" type="submit">Filtrar</button></div>
    </form>

    <p class="muted">Mostrando <?= h((string) count($rows)) ?> de <?= h((string) $totalRows) ?> (página <?= h((string) $page) ?> / <?= h((string) $totalPages) ?>)</p>

    <div style="overflow:auto;">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <?php if (!$searchInfo): ?><th>Busca</th><?php endif; ?>
            <th>Nome</th>
            <th>Nota</th>
            <th>Aval.</th>
            <th>Categoria</th>
            <th>Cidade/UF</th>
            <th>Telefone</th>
            <th>Site</th>
            <th>addweb</th>
            <th>Coletado</th>
            <th class="nowrap">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
            $tel = first_phone($r['telefones_json'] ?? null);
            $site = (string) ($r['website'] ?? '');
            ?>
            <tr>
              <td class="nowrap"><?= h((string) $r['id']) ?></td>
              <?php if (!$searchInfo): ?>
                <td class="nowrap">
                  <?php $sid = (int) ($r['search_id'] ?? 0); ?>
                  <?php if ($sid > 0): ?>
                    <a href="index.php?search_id=<?= h((string) $sid) ?>"><?= h((string) ($r['keyword'] ?? '') . ' ' . (string) ($r['localizacao'] ?? '')) ?></a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td><?= h((string) $r['nome']) ?></td>
              <td><?= h((string) ($r['nota'] ?? '')) ?></td>
              <td><?= h((string) ($r['total_avaliacoes'] ?? '')) ?></td>
              <td><?= h((string) $r['categoria']) ?></td>
              <td><?= h(trim(implode(' / ', array_filter([(string) $r['cidade'], (string) $r['uf']])))) ?></td>
              <td><?= h($tel) ?></td>
              <td><?php if ($site !== ''): ?><a href="<?= h($site) ?>" target="_blank" rel="noopener noreferrer"><?= h(mb_substr($site, 0, 40)) ?><?= mb_strlen($site) > 40 ? '…' : '' ?></a><?php endif; ?></td>
              <td><?= h((string) $r['addweb']) ?></td>
              <td class="nowrap"><?= h((string) $r['coletado_em']) ?></td>
              <td class="nowrap">
                <?php
                $lid = (int) $r['id'];
                $sidRow = (int) ($r['search_id'] ?? 0);
                ?>
                <div class="row-actions">
                  <a class="icon-btn" href="lead.php?id=<?= h((string) $lid) ?>" title="Ver lead" aria-label="Ver lead">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                  </a>
                  <a class="icon-btn" href="lead.php?id=<?= h((string) $lid) ?>#lead-edit" title="Editar lead" aria-label="Editar lead">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                  </a>
                  <form method="post" action="lead_delete.php" onsubmit="return confirm('Excluir este lead permanentemente?');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
                    <input type="hidden" name="id" value="<?= h((string) $lid) ?>" />
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
            <tr><td colspan="<?= $searchInfo ? 11 : 12 ?>">Nenhum registro.</td></tr>
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

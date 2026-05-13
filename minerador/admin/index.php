<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

$legacyListKeys = ['search_id', 'fq', 'nome', 'cidade', 'estado', 'pais', 'date_from', 'date_to', 'tem_site', 'order', 'dir'];
$redirectToLeads = false;
foreach ($legacyListKeys as $k) {
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
    if ($k === 'search_id' && (int) $s <= 0) {
        continue;
    }
    $redirectToLeads = true;
    break;
}
$pageGet = $_GET['page'] ?? null;
if (!$redirectToLeads && $pageGet !== null && is_scalar($pageGet) && (int) (string) $pageGet > 1) {
    $redirectToLeads = true;
}
if ($redirectToLeads) {
    header('Location: leads.php?' . http_build_query($_GET));
    exit;
}

$pdo = minerador_pdo();

[$scopeSql, $scopeParams] = minerador_admin_lead_scope_sql(true);
$kpiFrom = 'FROM minerador_leads l LEFT JOIN minerador_searches s ON s.id = l.search_id WHERE 1=1 ' . $scopeSql;

try {
    $stKpi = $pdo->prepare('SELECT COUNT(*) ' . $kpiFrom);
    $stKpi->execute($scopeParams);
    $kpiTotal = (int) $stKpi->fetchColumn();

    $stKpi = $pdo->prepare('SELECT COUNT(*) ' . $kpiFrom . ' AND DATE(l.created_at) = CURDATE()');
    $stKpi->execute($scopeParams);
    $kpiToday = (int) $stKpi->fetchColumn();

    $stKpi = $pdo->prepare("SELECT COUNT(*) " . $kpiFrom . " AND (l.website IS NULL OR l.website = '')");
    $stKpi->execute($scopeParams);
    $kpiNoSite = (int) $stKpi->fetchColumn();

    $stKpi = $pdo->prepare('SELECT COUNT(DISTINCT l.query_text) ' . $kpiFrom);
    $stKpi->execute($scopeParams);
    $kpiQueries = (int) $stKpi->fetchColumn();
} catch (Throwable $e) {
    error_log(sprintf(
        '[minerador index.php KPI] %s @ %s:%d | kpiFrom=%s',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $kpiFrom
    ));
    http_response_code(500);
    exit('Erro ao carregar os indicadores da página inicial.');
}

$ss = minerador_admin_nav_scope_suffix();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Home — Minerador.pt</title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    a { color:#93c5fd; }
    <?= minerador_admin_header_css() ?>
    main { padding:18px 20px 40px; max-width:1400px; margin:0 auto; }
    .kpis { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:12px; margin-bottom:22px; }
    .kpi { background:#111827; border:1px solid #1f2937; border-radius:10px; padding:12px 14px; }
    .kpi .v { font-size:22px; font-weight:700; }
    .kpi .l { font-size:12px; color:#9ca3af; margin-top:4px; }
    .btn { display:inline-block; padding:9px 14px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; border:none; cursor:pointer; }
    .btn.secondary { background:#374151; }
    .muted { color:#9ca3af; font-size:13px; }
  </style>
</head>
<body>
  <?php minerador_admin_render_page_header(null, []); ?>
  <main>
    <div class="kpis">
      <div class="kpi"><div class="v"><?= h((string) $kpiTotal) ?></div><div class="l">Total de leads</div></div>
      <div class="kpi"><div class="v"><?= h((string) $kpiToday) ?></div><div class="l">Novos hoje (created_at)</div></div>
      <div class="kpi"><div class="v"><?= h((string) $kpiNoSite) ?></div><div class="l">Sem website</div></div>
      <div class="kpi"><div class="v"><?= h((string) $kpiQueries) ?></div><div class="l">Queries distintas</div></div>
    </div>
    <p class="muted">Resumo do universo de dados da sua sessão<?= minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine' ? ' — apenas leads e buscas do token do config.php.' : (minerador_admin_is_config_admin() ? ' — todos os leads e buscas (sem filtro).' : '.') ?></p>
    <p><a class="btn" href="leads.php<?= h($ss) ?>">Abrir listagem de leads</a></p>
  </main>
</body>
</html>

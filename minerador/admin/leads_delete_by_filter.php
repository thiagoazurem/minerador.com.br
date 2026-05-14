<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
minerador_csrf_check();

$pdo = minerador_pdo();

$allowedReturn = ['scope', 'search_id', 'fq', 'nome', 'cidade', 'estado', 'pais', 'date_from', 'date_to', 'tem_site', 'order', 'dir', 'page'];
parse_str((string) ($_POST['return_qs'] ?? ''), $parsed);
$q = [];
if (is_array($parsed)) {
    foreach ($allowedReturn as $k) {
        if (!isset($parsed[$k]) || is_array($parsed[$k])) {
            continue;
        }
        $s = trim((string) $parsed[$k]);
        if ($s === '') {
            continue;
        }
        $q[$k] = $s;
    }
}

$target = 'leads.php';
if ($q !== []) {
    $target .= '?' . http_build_query($q);
}

$scopeOverride = null;
if (minerador_admin_is_config_admin()) {
    $scopeOverride = (string) ($_POST['scope'] ?? '') === 'mine' ? 'mine' : 'all';
}
$scopeForSearchCheck = minerador_admin_is_config_admin() ? $scopeOverride : null;

$searchIdFromQ = (int) ($q['search_id'] ?? 0);
if ($searchIdFromQ > 0) {
    $st = $pdo->prepare('SELECT * FROM minerador_searches WHERE id = ? LIMIT 1');
    $st->execute([$searchIdFromQ]);
    $searchRow = $st->fetch();
    if (!is_array($searchRow) || !minerador_admin_can_manage_search($searchRow, $scopeForSearchCheck)) {
        $sep = str_contains($target, '?') ? '&' : '?';
        header('Location: ' . $target . $sep . 'filter_delete_forbidden=1');
        exit;
    }
}

$terms = minerador_settings_parse_ignore_terms((string) ($_POST['terms'] ?? ''));
if ($terms === []) {
    $sep = str_contains($target, '?') ? '&' : '?';
    header('Location: ' . $target . $sep . 'filter_delete_no_terms=1');
    exit;
}

[$filterWhere, $filterParams] = minerador_admin_leads_filter_sql_from_query($q);
[$scopeSql, $scopeParams] = minerador_admin_lead_scope_sql(true, $scopeOverride);
$fullWhere = $filterWhere . $scopeSql;
$params = array_merge($filterParams, $scopeParams);

$sql = 'SELECT l.id, l.nome, l.website, l.query_text, l.keyword, l.endereco_completo, l.categoria, l.cidade, l.estado, l.pais, l.url_resultado, l.comentarios
        FROM minerador_leads l
        LEFT JOIN minerador_searches s ON s.id = l.search_id
        WHERE 1=1 ' . $fullWhere;

$st = $pdo->prepare($sql);
$st->execute($params);

$ids = [];
while ($row = $st->fetch()) {
    if (!is_array($row)) {
        continue;
    }
    $hay = minerador_lead_ignore_haystack([
        'nome' => $row['nome'] ?? '',
        'website' => $row['website'] ?? '',
        'query_text' => $row['query_text'] ?? '',
        'keyword' => $row['keyword'] ?? '',
        'endereco_completo' => $row['endereco_completo'] ?? '',
        'categoria' => $row['categoria'] ?? '',
        'cidade' => $row['cidade'] ?? '',
        'estado' => $row['estado'] ?? '',
        'pais' => $row['pais'] ?? '',
        'url_resultado' => $row['url_resultado'] ?? '',
        'comentarios' => $row['comentarios'] ?? '',
    ]);
    if (minerador_lead_haystack_matches_ignore_terms($hay, $terms)) {
        $ids[] = (int) ($row['id'] ?? 0);
    }
}

$ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

if ($ids === []) {
    $sep = str_contains($target, '?') ? '&' : '?';
    header('Location: ' . $target . $sep . 'filter_delete_none=1');
    exit;
}

$n = count($ids);
try {
    foreach (array_chunk($ids, 300) as $chunk) {
        $place = implode(',', array_fill(0, count($chunk), '?'));
        $del = $pdo->prepare('DELETE FROM minerador_leads WHERE id IN (' . $place . ')');
        $del->execute($chunk);
    }
} catch (Throwable $e) {
    error_log(sprintf('[minerador leads_delete_by_filter] %s @ %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    $sep = str_contains($target, '?') ? '&' : '?';
    header('Location: ' . $target . $sep . 'filter_delete_err=1');
    exit;
}

$sep = str_contains($target, '?') ? '&' : '?';
header('Location: ' . $target . $sep . 'filter_deleted=' . (string) max(0, $n));
exit;

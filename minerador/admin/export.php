<?php

declare(strict_types=1);



require_once __DIR__ . '/_bootstrap.php';

minerador_admin_require_login();



$pdo = minerador_pdo();



function build_filters_export(): array

{

    $where = [];

    $params = [];



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



    $searchId = (int) ($_GET['search_id'] ?? 0);

    if ($searchId > 0) {

        $where[] = 'l.search_id = ?';

        $params[] = $searchId;

    }



    $sql = $where ? (' AND ' . implode(' AND ', $where)) : '';

    return [$sql, $params];

}



[$whereSql, $filterParams] = build_filters_export();

[$scopeSql, $scopeParams] = minerador_admin_lead_scope_sql();

$whereSql .= $scopeSql;

$filterParams = array_merge($filterParams, $scopeParams);



$order = (string) ($_GET['order'] ?? 'coletado_em');

$allowed = ['id', 'nome', 'coletado_em', 'cidade', 'estado', 'pais', 'pagina', 'nota', 'rate_num', 'query_text', 'website', 'qualificacao'];

if (!in_array($order, $allowed, true)) {

    $order = 'coletado_em';

}

$dir = strtolower((string) ($_GET['dir'] ?? 'desc'));

$dirSql = $dir === 'asc' ? 'ASC' : 'DESC';



$sql = 'SELECT l.id, l.nome, l.nota, l.rate_num, l.categoria, l.endereco_completo, l.cidade, l.estado, l.pais, l.cep, l.website, l.mapurl, l.phones, l.qualificacao, l.comentarios, l.query_text, l.pagina, l.url_resultado, l.coletado_em, l.created_at FROM minerador_leads l WHERE 1=1 '

    . $whereSql

    . ' ORDER BY l.`' . str_replace('`', '', $order) . '` ' . $dirSql;



$stmt = $pdo->prepare($sql);

$stmt->execute($filterParams);



$fname = 'minerador_leads_' . date('Y-m-d_His') . '.csv';



header('Content-Type: text/csv; charset=utf-8');

header('Content-Disposition: attachment; filename="' . $fname . '"');



$out = fopen('php://output', 'w');

fwrite($out, "\xEF\xBB\xBF");



$headers = [

    'id',

    'nome',

    'nota',

    'rate_num',

    'categoria',

    'endereco_completo',

    'cidade',

    'estado',

    'pais',

    'cep',

    'website',

    'mapurl',

    'phones',

    'qualificacao',

    'comentarios',

    'query_text',

    'pagina',

    'url_resultado',

    'coletado_em',

    'created_at',

];

fputcsv($out, $headers, ';');



while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    fputcsv($out, array_map(static function ($v) {

        if ($v === null) {

            return '';

        }

        return (string) $v;

    }, $row), ';');

}



fclose($out);

exit;


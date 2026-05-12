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

[$whereSql, $filterParams] = build_filters_export();

$order = (string) ($_GET['order'] ?? 'coletado_em');
$allowed = ['id', 'nome', 'coletado_em', 'cidade', 'uf', 'pagina', 'nota', 'total_avaliacoes', 'query_text', 'website', 'addweb'];
if (!in_array($order, $allowed, true)) {
    $order = 'coletado_em';
}
$dir = strtolower((string) ($_GET['dir'] ?? 'desc'));
$dirSql = $dir === 'asc' ? 'ASC' : 'DESC';

$sql = 'SELECT id, nome, nota, total_avaliacoes, categoria, endereco_completo, cidade, uf, cep, website, telefones_json, addweb, query_text, pagina, url_resultado, coletado_em, created_at FROM minerador_leads WHERE 1=1 '
    . $whereSql
    . ' ORDER BY `' . str_replace('`', '', $order) . '` ' . $dirSql;

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
    'total_avaliacoes',
    'categoria',
    'endereco_completo',
    'cidade',
    'uf',
    'cep',
    'website',
    'telefones_json',
    'addweb',
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

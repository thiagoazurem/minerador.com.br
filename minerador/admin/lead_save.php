<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
minerador_csrf_check();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID inválido');
}

$nota = trim((string) ($_POST['nota'] ?? ''));
$notaSql = null;
if ($nota !== '') {
    $f = (float) str_replace(',', '.', $nota);
    if ($f >= 0 && $f <= 5) {
        $notaSql = $f;
    }
}

$total = trim((string) ($_POST['total_avaliacoes'] ?? ''));
$totalSql = null;
if ($total !== '' && is_numeric($total)) {
    $totalSql = max(0, (int) $total);
}

$phonesRaw = (string) ($_POST['telefones'] ?? '');
$phonesArr = [];
foreach (preg_split('/[\r\n]+/', $phonesRaw) ?: [] as $line) {
    $t = trim((string) $line);
    if ($t !== '') {
        $phonesArr[] = $t;
    }
}

$addweb = ((string) ($_POST['addweb'] ?? 'nao')) === 'sim' ? 'sim' : 'nao';

$pdo = minerador_pdo();
$up = $pdo->prepare(
    'UPDATE minerador_leads SET
        nome = :nome,
        nota = :nota,
        total_avaliacoes = :total_avaliacoes,
        categoria = :categoria,
        endereco_completo = :endereco_completo,
        cidade = :cidade,
        uf = :uf,
        cep = :cep,
        website = :website,
        telefones_json = :telefones_json,
        addweb = :addweb
     WHERE id = :id'
);
$up->execute([
    ':nome' => mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 512, 'UTF-8'),
    ':nota' => $notaSql,
    ':total_avaliacoes' => $totalSql,
    ':categoria' => mb_substr(trim((string) ($_POST['categoria'] ?? '')), 0, 255, 'UTF-8'),
    ':endereco_completo' => trim((string) ($_POST['endereco_completo'] ?? '')),
    ':cidade' => mb_substr(trim((string) ($_POST['cidade'] ?? '')), 0, 255, 'UTF-8'),
    ':uf' => mb_strtoupper(mb_substr(trim((string) ($_POST['uf'] ?? '')), 0, 2, 'UTF-8'), 'UTF-8'),
    ':cep' => mb_substr(trim((string) ($_POST['cep'] ?? '')), 0, 16, 'UTF-8'),
    ':website' => mb_substr(trim((string) ($_POST['website'] ?? '')), 0, 2048, 'UTF-8'),
    ':telefones_json' => json_encode($phonesArr, JSON_UNESCAPED_UNICODE),
    ':addweb' => $addweb,
    ':id' => $id,
]);

header('Location: lead.php?id=' . $id . '&saved=1');
exit;

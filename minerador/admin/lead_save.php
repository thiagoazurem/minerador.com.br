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



$pdo = minerador_pdo();

$chk = $pdo->prepare(

    'SELECT l.search_id, s.owner_key, s.owner_user_id FROM minerador_leads l ' .

    'LEFT JOIN minerador_searches s ON s.id = l.search_id WHERE l.id = ? LIMIT 1'

);

$chk->execute([$id]);

$meta = $chk->fetch();

if (!$meta) {

    http_response_code(404);

    exit('Lead não encontrado');

}

$sid = (int) ($meta['search_id'] ?? 0);

if ($sid <= 0) {

    if (!minerador_admin_is_config_admin() || (string) ($_POST['scope'] ?? '') === 'mine') {

        http_response_code(403);

        exit('Sem permissão');

    }

} else {

    $smini = [

        'owner_key' => (string) ($meta['owner_key'] ?? ''),

        'owner_user_id' => isset($meta['owner_user_id']) ? (int) $meta['owner_user_id'] : null,

    ];

    if (!minerador_admin_can_manage_search($smini, (string) ($_POST['scope'] ?? '') === 'mine' ? 'mine' : null)) {

        http_response_code(403);

        exit('Sem permissão');

    }

}



$nota = trim((string) ($_POST['nota'] ?? ''));

$notaSql = null;

if ($nota !== '') {

    $f = (float) str_replace(',', '.', $nota);

    if ($f >= 0 && $f <= 5) {

        $notaSql = $f;

    }

}



$total = trim((string) ($_POST['rate_num'] ?? $_POST['total_avaliacoes'] ?? ''));

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



$qualRaw = trim((string) ($_POST['qualificacao'] ?? ''));

$allowedQual = ['baixo', 'medio', 'alto', 'max'];

$qualificacaoSql = null;

if ($qualRaw !== '' && in_array($qualRaw, $allowedQual, true)) {

    $qualificacaoSql = $qualRaw;

}



$comentarios = mb_substr(trim((string) ($_POST['comentarios'] ?? '')), 0, 65535, 'UTF-8');

$comentariosSql = $comentarios === '' ? null : $comentarios;



$mapurlSql = minerador_normalize_mapurl_for_db(trim((string) ($_POST['mapurl'] ?? '')) ?: null);



$up = $pdo->prepare(

    'UPDATE minerador_leads SET

        nome = :nome,

        nota = :nota,

        rate_num = :rate_num,

        categoria = :categoria,

        endereco_completo = :endereco_completo,

        cidade = :cidade,

        estado = :estado,

        pais = :pais,

        cep = :cep,

        website = :website,

        mapurl = :mapurl,

        phones = :phones,

        qualificacao = :qualificacao,

        comentarios = :comentarios

     WHERE id = :id'

);

$up->execute([

    ':nome' => mb_substr(trim((string) ($_POST['nome'] ?? '')), 0, 512, 'UTF-8'),

    ':nota' => $notaSql,

    ':rate_num' => $totalSql,

    ':categoria' => mb_substr(trim((string) ($_POST['categoria'] ?? '')), 0, 255, 'UTF-8'),

    ':endereco_completo' => trim((string) ($_POST['endereco_completo'] ?? '')),

    ':cidade' => mb_substr(trim((string) ($_POST['cidade'] ?? '')), 0, 255, 'UTF-8'),

    ':estado' => mb_substr(trim((string) ($_POST['estado'] ?? '')), 0, 64, 'UTF-8'),

    ':pais' => mb_substr(trim((string) ($_POST['pais'] ?? '')), 0, 128, 'UTF-8'),

    ':cep' => mb_substr(trim((string) ($_POST['cep'] ?? '')), 0, 16, 'UTF-8'),

    ':website' => mb_substr(trim((string) ($_POST['website'] ?? '')), 0, 2048, 'UTF-8'),

    ':mapurl' => $mapurlSql,

    ':phones' => json_encode($phonesArr, JSON_UNESCAPED_UNICODE),

    ':qualificacao' => $qualificacaoSql,

    ':comentarios' => $comentariosSql,

    ':id' => $id,

]);



header('Location: lead.php?id=' . $id . '&saved=1' . (((string) ($_POST['scope'] ?? '') === 'mine') ? '&scope=mine' : ''));

exit;


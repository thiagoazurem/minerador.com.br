<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
minerador_admin_require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'ID inválido';
    exit;
}

$pdo = minerador_pdo();
$st = $pdo->prepare(
    'SELECT l.*, s.slug AS search_slug ' .
    'FROM minerador_leads l LEFT JOIN minerador_searches s ON s.id = l.search_id ' .
    'WHERE l.id = ? LIMIT 1'
);
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    echo 'Lead não encontrado';
    exit;
}

$phones = [];
if (!empty($row['telefones_json'])) {
    $tmp = json_decode((string) $row['telefones_json'], true);
    if (is_array($tmp)) {
        $phones = $tmp;
    }
}
$phonesLines = implode("\n", array_map('strval', $phones));

$srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{font-family:system-ui,sans-serif;padding:12px;color:#111;background:#fff}</style></head><body>'
    . (string) $row['aba_sobre_html']
    . '</body></html>';
$srcdocEsc = htmlspecialchars($srcdoc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$csrf = minerador_csrf_token();
$saved = isset($_GET['saved']);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lead #<?= h((string) $id) ?> — Minerador</title>
  <style>
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    header { padding:14px 18px; background:#111827; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; }
    main { padding:18px; max-width:980px; margin:0 auto; }
    a { color:#93c5fd; }
    .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#374151; color:#fff; text-decoration:none; border:none; cursor:pointer; font-weight:600; }
    .btn.primary { background:#2563eb; }
    .btn.danger { background:#7f1d1d; }
    iframe { width:100%; min-height:480px; border:1px solid #374151; border-radius:8px; background:#fff; }
    pre { white-space:pre-wrap; word-break:break-word; background:#111827; padding:12px; border-radius:8px; border:1px solid #1f2937; }
    .grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:12px; }
    .grid label { display:block; font-size:12px; color:#9ca3af; }
    .grid input, .grid select, .grid textarea {
      width:100%; padding:8px 10px; border-radius:8px; border:1px solid #374151;
      background:#0b1220; color:#e5e7eb; margin-top:4px; font:inherit;
    }
    .full { grid-column: 1 / -1; }
    .actions { display:flex; gap:10px; margin-top:14px; flex-wrap:wrap; }
    .flash { background:#065f46; color:#d1fae5; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
    h2 { font-size:16px; margin:24px 0 8px; }
  </style>
</head>
<body>
  <header>
    <strong>Lead #<?= h((string) $id) ?></strong>
    <div>
      <a class="btn" href="index.php<?= $row['search_id'] ? '?search_id=' . (int) $row['search_id'] : '' ?>">Voltar</a>
      <a class="btn" href="searches.php">Buscas</a>
      <a class="btn" href="logout.php">Sair</a>
    </div>
  </header>
  <main>
    <?php if ($saved): ?><div class="flash">Alterações salvas.</div><?php endif; ?>

    <p style="color:#9ca3af;font-size:13px;">
      Busca:
      <?php if ($row['search_slug']): ?>
        <a href="index.php?search_id=<?= h((string) (int) $row['search_id']) ?>"><?= h((string) $row['search_slug']) ?></a>
        — <?= h((string) $row['keyword']) ?> <?= h((string) $row['localizacao']) ?>
      <?php else: ?>
        (sem busca associada)
      <?php endif; ?>
      &nbsp;·&nbsp; Coletado em <?= h((string) $row['coletado_em']) ?>
      &nbsp;·&nbsp; Página <?= h((string) $row['pagina']) ?>
    </p>

    <h2 id="lead-edit">Editar dados</h2>
    <form method="post" action="lead_save.php">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
      <input type="hidden" name="id" value="<?= h((string) $id) ?>" />
      <div class="grid">
        <div class="full"><label>Nome</label><input name="nome" value="<?= h((string) $row['nome']) ?>" /></div>
        <div><label>Nota (ex.: 4.5)</label><input name="nota" value="<?= h((string) ($row['nota'] ?? '')) ?>" /></div>
        <div><label>Total de avaliações</label><input name="total_avaliacoes" value="<?= h((string) ($row['total_avaliacoes'] ?? '')) ?>" /></div>
        <div class="full"><label>Categoria</label><input name="categoria" value="<?= h((string) $row['categoria']) ?>" /></div>
        <div class="full"><label>Endereço completo</label><input name="endereco_completo" value="<?= h((string) $row['endereco_completo']) ?>" /></div>
        <div><label>Cidade</label><input name="cidade" value="<?= h((string) $row['cidade']) ?>" /></div>
        <div><label>UF</label><input name="uf" maxlength="2" value="<?= h((string) $row['uf']) ?>" /></div>
        <div><label>CEP</label><input name="cep" value="<?= h((string) $row['cep']) ?>" /></div>
        <div><label>addweb</label>
          <select name="addweb">
            <option value="nao" <?= $row['addweb'] === 'nao' ? 'selected' : '' ?>>não</option>
            <option value="sim" <?= $row['addweb'] === 'sim' ? 'selected' : '' ?>>sim</option>
          </select>
        </div>
        <div class="full"><label>Website</label><input name="website" value="<?= h((string) $row['website']) ?>" /></div>
        <div class="full"><label>Telefones (um por linha)</label><textarea name="telefones" rows="4"><?= h($phonesLines) ?></textarea></div>
      </div>
      <div class="actions">
        <button type="submit" class="btn primary">Salvar</button>
      </div>
    </form>

    <form method="post" action="lead_delete.php" onsubmit="return confirm('Excluir este lead permanentemente?');" style="margin-top:18px;">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
      <input type="hidden" name="id" value="<?= h((string) $id) ?>" />
      <?php if ($row['search_id']): ?>
        <input type="hidden" name="back_search_id" value="<?= h((string) (int) $row['search_id']) ?>" />
      <?php endif; ?>
      <button type="submit" class="btn danger">Excluir lead</button>
    </form>

    <h2>Aba “Sobre” (HTML bruto, sandbox)</h2>
    <iframe title="Sobre" sandbox="" srcdoc="<?= $srcdocEsc ?>"></iframe>
  </main>
</body>
</html>

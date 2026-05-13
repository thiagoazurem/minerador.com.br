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
    'SELECT l.*, s.slug AS search_slug, s.owner_key AS search_owner_key, s.owner_user_id AS search_owner_user_id, s.localizacao AS search_localizacao ' .
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

$sid = (int) ($row['search_id'] ?? 0);
if ($sid <= 0) {
    if (!minerador_admin_is_config_admin() || (string) ($_GET['scope'] ?? '') === 'mine') {
        http_response_code(404);
        echo 'Lead não encontrado';
        exit;
    }
} else {
    $smini = [
        'owner_key' => (string) ($row['search_owner_key'] ?? ''),
        'owner_user_id' => isset($row['search_owner_user_id']) ? (int) $row['search_owner_user_id'] : null,
    ];
    if (!minerador_admin_can_manage_search($smini)) {
        http_response_code(404);
        echo 'Lead não encontrado';
        exit;
    }
}

$galleryRows = [];
try {
    $galleryStmt = $pdo->prepare(
        'SELECT id, kind, url, thumb_url, sort_order FROM minerador_gallery WHERE lead_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $galleryStmt->execute([$id]);
    $galleryRows = $galleryStmt->fetchAll();
} catch (Throwable $e) {
    $galleryRows = [];
}

$phones = [];
if (!empty($row['phones'])) {
    $tmp = json_decode((string) $row['phones'], true);
    if (is_array($tmp)) {
        $phones = $tmp;
    }
}
$phonesLines = implode("\n", array_map('strval', $phones));

$rawQual = $row['qualificacao'] ?? null;
$qualificacaoCurr = ($rawQual === null || $rawQual === '') ? '' : (string) $rawQual;
if ($qualificacaoCurr !== '' && !in_array($qualificacaoCurr, ['baixo', 'medio', 'alto', 'max'], true)) {
    $qualificacaoCurr = '';
}

$comentariosBody = (string) ($row['comentarios'] ?? '');

$estadoVal = trim((string) ($row['estado'] ?? $row['uf'] ?? ''));
$websiteTrim = trim((string) ($row['website'] ?? ''));
$mapurlTrim = trim((string) ($row['mapurl'] ?? ''));
$websiteOpen = $websiteTrim !== '' && (bool) preg_match('#^https?://#i', $websiteTrim);
$mapOpen = $mapurlTrim !== '' && (bool) preg_match('#^https?://#i', $mapurlTrim);

$csrf = minerador_csrf_token();
$saved = isset($_GET['saved']);

$leadBackQ = [];
if ((int) ($row['search_id'] ?? 0) > 0) {
    $leadBackQ['search_id'] = (int) $row['search_id'];
}
if (minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine') {
    $leadBackQ['scope'] = 'mine';
}
$leadBackHref = $leadBackQ === [] ? 'leads.php' : ('leads.php?' . http_build_query($leadBackQ));

$leadIdxBuscaQ = [];
if ((int) ($row['search_id'] ?? 0) > 0) {
    $leadIdxBuscaQ['search_id'] = (int) $row['search_id'];
}
if (minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine') {
    $leadIdxBuscaQ['scope'] = 'mine';
}
$leadIdxBuscaHref = $leadIdxBuscaQ === [] ? 'leads.php' : ('leads.php?' . http_build_query($leadIdxBuscaQ));

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Lead #<?= h((string) $id) ?> — Minerador.pt</title>
  <style>
    body { font-family: system-ui, sans-serif; margin:0; background:#0b1220; color:#e5e7eb; }
    <?= minerador_admin_header_css() ?>
    main { padding:18px; max-width:980px; margin:0 auto; }
    @media (min-width: 960px) {
      main { padding-right: 220px; }
    }
    .lead-qual-dock {
      position: fixed; z-index: 30; right: 10px; bottom: 10px; width: min(100px, calc(100vw - 32px));
      padding: 8px 12px 12px 12px; background: yellow; border: 1px solid #374151; border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0,0,0,.35);text-align:center;
    }
    .lead-qual-dock label { display: block; font-size: 12px; color: #000; margin: 0 0 8px 0;font-weight:bold; }
    .lead-qual-dock select {
      font-size:18px;
      display: block; width: 100%; padding: 4px 6px; border-radius: 8px; border: 1px solid #374151;
      background: #0b1220; color: #e5e7eb; font: inherit; box-sizing: border-box;
    }
    @media (max-width: 799px) {
      .lead-qual-dock {
        left: 12px; right: 12px; top: auto; bottom: 12px; width: auto; max-width: 320px;
        margin-left: auto;
      }
    }
    a { color:#93c5fd; }
    .btn { display:inline-block; padding:8px 12px; border-radius:8px; background:#374151; color:#fff; text-decoration:none; border:none; cursor:pointer; font-weight:600; }
    .btn.secondary { background:#374151; color:#fff; }
    .btn.primary { background:#2563eb; }
    .btn.danger { background:#7f1d1d; }
    pre { white-space:pre-wrap; word-break:break-word; background:#111827; padding:12px; border-radius:8px; border:1px solid #1f2937; }
    .grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:12px; }
    .grid label { display:block; font-size:12px; color:#9ca3af; }
    .grid input, .grid select, .grid textarea {
      width:100%; padding:8px 10px; border-radius:8px; border:1px solid #374151;
      background:#0b1220; color:#e5e7eb; margin-top:4px; font:inherit;
    }
    .input-with-btn { display:flex; gap:8px; align-items:stretch; margin-top:4px; }
    .input-with-btn input { flex:1; min-width:0; margin-top:0; }
    .input-with-btn .btn { flex-shrink:0; align-self:center; white-space:nowrap; }
    .input-with-btn .btn.disabled { opacity:0.45; pointer-events:none; cursor:not-allowed; }
    .full { grid-column: 1 / -1; }
    .actions { display:flex; gap:10px; margin-top:14px; flex-wrap:wrap; }
    .flash { background:#065f46; color:#d1fae5; padding:10px 14px; border-radius:8px; margin-bottom:14px; }
    h2 { font-size:16px; margin:24px 0 8px; }
    .gallery-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap:12px; margin-top:10px; }
    .gallery-cell {
      border:1px solid #374151; border-radius:8px; overflow:hidden; background:#111827;
      display:flex; flex-direction:column; min-height:120px;
    }
    .gallery-cell img, .gallery-cell video { width:100%; height:140px; object-fit:cover; display:block; vertical-align:top; background:#0b1220; }
    .gallery-cell video { height:auto; max-height:220px; }
    .gallery-meta { font-size:11px; color:#9ca3af; padding:6px 8px; border-top:1px solid #1f2937; }
  </style>
</head>
<body>
  <?php minerador_admin_render_page_header('Lead #' . h((string) $id), []); ?>
  <aside class="lead-qual-dock" aria-label="Qualificação do lead">
    <label for="lead_qualificacao">QUALIFICAÇÃO</label>
    <select id="lead_qualificacao" name="qualificacao" form="leadEditForm">
      <option value="" <?= $qualificacaoCurr === '' ? 'selected' : '' ?>>(vazio)</option>
      <option value="baixo" <?= $qualificacaoCurr === 'baixo' ? 'selected' : '' ?>>Baixo</option>
      <option value="medio" <?= $qualificacaoCurr === 'medio' ? 'selected' : '' ?>>Médio</option>
      <option value="alto" <?= $qualificacaoCurr === 'alto' ? 'selected' : '' ?>>Alto</option>
      <option value="max" <?= $qualificacaoCurr === 'max' ? 'selected' : '' ?>>Máx</option>
    </select>
  </aside>
  <main>
    <?php if ($saved): ?><div class="flash">Alterações salvas.</div><?php endif; ?>

    <p style="color:#9ca3af;font-size:13px;">
      Busca:
      <?php if ($row['search_slug']): ?>
        <a href="<?= h($leadIdxBuscaHref) ?>"><?= h((string) $row['search_slug']) ?></a>
        — <?= h((string) $row['keyword']) ?> <?= h((string) ($row['search_localizacao'] ?? '')) ?>
      <?php else: ?>
        (sem busca associada)
      <?php endif; ?>
      &nbsp;·&nbsp; Coletado em <?= h((string) $row['coletado_em']) ?>
      &nbsp;·&nbsp; Página <?= h((string) $row['pagina']) ?>
    </p>

    <h2 id="lead-edit">Editar dados</h2>
    <form id="leadEditForm" method="post" action="lead_save.php">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>" />
      <input type="hidden" name="id" value="<?= h((string) $id) ?>" />
      <?php if (minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine'): ?>
        <input type="hidden" name="scope" value="mine" />
      <?php endif; ?>
      <div class="grid">
        <div class="full"><label>Nome</label><input name="nome" value="<?= h((string) $row['nome']) ?>" /></div>
        <div><label>Nota (ex.: 4.5)</label><input name="nota" value="<?= h((string) ($row['nota'] ?? '')) ?>" /></div>
        <div><label>Total de avaliações</label><input name="rate_num" value="<?= h((string) ($row['rate_num'] ?? '')) ?>" /></div>
        <div class="full"><label>Categoria</label><input name="categoria" value="<?= h((string) $row['categoria']) ?>" /></div>
        <div class="full"><label>Endereço completo</label><input name="endereco_completo" value="<?= h((string) $row['endereco_completo']) ?>" /></div>
        <div><label>Cidade</label><input name="cidade" value="<?= h((string) $row['cidade']) ?>" /></div>
        <div><label>Estado</label><input name="estado" maxlength="64" value="<?= h($estadoVal) ?>" /></div>
        <div><label>País</label><input name="pais" maxlength="128" value="<?= h((string) ($row['pais'] ?? '')) ?>" /></div>
        <div><label>CEP</label><input name="cep" value="<?= h((string) $row['cep']) ?>" /></div>
        <div class="full">
          <label>Website</label>
          <div class="input-with-btn">
            <input name="website" value="<?= h((string) $row['website']) ?>" />
            <?php if ($websiteOpen): ?>
              <a class="btn" target="_blank" rel="noopener noreferrer" href="<?= h($websiteTrim) ?>">Abrir</a>
            <?php else: ?>
              <span class="btn disabled" aria-disabled="true">Abrir</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="full">
          <label>URL do Google Maps</label>
          <div class="input-with-btn">
            <input name="mapurl" type="text" value="<?= h((string) ($row['mapurl'] ?? '')) ?>" placeholder="https://maps.google.com/maps?…" autocomplete="off" />
            <?php if ($mapOpen): ?>
              <a class="btn" target="_blank" rel="noopener noreferrer" href="<?= h($mapurlTrim) ?>">Abrir</a>
            <?php else: ?>
              <span class="btn disabled" aria-disabled="true">Abrir</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="full"><label>Telefones (um por linha)</label><textarea name="telefones" rows="4"><?= h($phonesLines) ?></textarea></div>
        <div class="full"><label>Comentários</label><textarea name="comentarios" rows="4"><?= h($comentariosBody) ?></textarea></div>
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
      <?php if (minerador_admin_is_config_admin() && (string) ($_GET['scope'] ?? '') === 'mine'): ?>
        <input type="hidden" name="scope" value="mine" />
      <?php endif; ?>
      <button type="submit" class="btn danger">Excluir lead</button>
    </form>

    <h2>Mídias (galeria)</h2>
    <?php if ($galleryRows === []): ?>
      <p style="color:#9ca3af;font-size:14px;">Nenhuma mídia registada para este lead.</p>
    <?php else: ?>
      <div class="gallery-grid">
        <?php foreach ($galleryRows as $g): ?>
          <?php
            $gKind = (string) ($g['kind'] ?? '');
            $gUrl = (string) ($g['url'] ?? '');
            $gThumb = (string) ($g['thumb_url'] ?? '');
          ?>
          <div class="gallery-cell">
            <?php if ($gKind === 'video' && $gUrl !== ''): ?>
              <video controls preload="metadata" playsinline<?= $gThumb !== '' ? ' poster="' . h($gThumb) . '"' : '' ?> src="<?= h($gUrl) ?>"></video>
            <?php elseif ($gKind === 'image' && $gUrl !== ''): ?>
              <img loading="lazy" alt="" src="<?= h($gUrl) ?>" />
            <?php else: ?>
              <div class="gallery-meta">Tipo desconhecido</div>
            <?php endif; ?>
            <div class="gallery-meta"><?= h($gKind) ?> · #<?= h((string) (int) ($g['id'] ?? 0)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </main>
</body>
</html>

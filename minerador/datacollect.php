<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap_db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Minerador-Token, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(bool $ok, array $extra = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<string, mixed> $meta
 */
function minerador_upsert_search(PDO $pdo, array $meta, string $ownerKey, ?int $ownerUserId): int
{
    $searchSlugRaw = trim((string) ($meta['search_slug'] ?? ''));
    if ($searchSlugRaw === '') {
        $searchSlugRaw = 'legacy-' . substr(hash('sha256', uniqid('', true)), 0, 16);
    }
    $searchSlug = mb_substr($searchSlugRaw, 0, 64, 'UTF-8');

    $keyword = mb_substr(trim((string) ($meta['keyword'] ?? '')), 0, 255, 'UTF-8');
    $localizacao = mb_substr(trim((string) ($meta['localizacao'] ?? '')), 0, 255, 'UTF-8');
    $queryText = mb_substr(trim((string) ($meta['query'] ?? '')), 0, 1024, 'UTF-8');

    $insSearch = $pdo->prepare(
        'INSERT INTO minerador_searches (slug, keyword, localizacao, query_text, owner_key, owner_user_id)
         VALUES (:slug, :keyword, :localizacao, :query_text, :owner_key, :owner_user_id)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $insSearch->execute([
        ':slug' => $searchSlug,
        ':keyword' => $keyword,
        ':localizacao' => $localizacao,
        ':query_text' => $queryText,
        ':owner_key' => $ownerKey,
        ':owner_user_id' => $ownerUserId,
    ]);
    $searchId = (int) $pdo->lastInsertId();
    if ($searchId === 0) {
        $selS = $pdo->prepare('SELECT id FROM minerador_searches WHERE owner_key = ? AND slug = ? LIMIT 1');
        $selS->execute([$ownerKey, $searchSlug]);
        $searchId = (int) $selS->fetchColumn();
    }

    return $searchId;
}

const MINERADOR_MEDIA_URL_MAX = 4096;

/**
 * Coluna comentarios: JSON (ex.: objeto com itens da extensão) ou texto legado.
 *
 * @param mixed $v
 */
function minerador_normalize_comentarios_for_db($v): ?string
{
    if ($v === null) {
        return null;
    }
    if (is_array($v)) {
        if ($v === []) {
            return null;
        }
        $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : mb_substr($json, 0, 65535, 'UTF-8');
    }
    $s = is_string($v) ? trim($v) : '';
    if ($s === '') {
        return null;
    }
    $dec = json_decode($s, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
        $json = json_encode($dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : mb_substr($json, 0, 65535, 'UTF-8');
    }

    return mb_substr($s, 0, 65535, 'UTF-8');
}

/**
 * @param array<string, mixed> $lead
 * @return list<array{kind: string, url: string, thumb: ?string}>
 */
function minerador_normalize_medias_from_lead(array $lead): array
{
    $raw = $lead['medias'] ?? null;
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? '')));
        if ($kind !== 'image' && $kind !== 'video') {
            continue;
        }
        $url = trim((string) ($row['url'] ?? ''));
        if ($url === '' || strlen($url) > MINERADOR_MEDIA_URL_MAX) {
            continue;
        }
        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }
        $thumb = trim((string) ($row['thumb'] ?? ''));
        if ($thumb !== '') {
            if (strlen($thumb) > MINERADOR_MEDIA_URL_MAX || !preg_match('#^https?://#i', $thumb)) {
                $thumb = '';
            }
        }
        $thumbNull = $thumb === '' ? null : $thumb;
        $out[] = ['kind' => $kind, 'url' => $url, 'thumb' => $thumbNull];
    }

    return $out;
}

/**
 * @param list<array{kind: string, url: string, thumb: ?string}> $items
 */
function minerador_insert_gallery_rows(PDO $pdo, int $leadId, array $items): void
{
    if ($items === []) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO minerador_gallery (lead_id, kind, url, thumb_url, sort_order) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($items as $i => $item) {
        $st->execute([
            $leadId,
            $item['kind'],
            $item['url'],
            $item['thumb'],
            $i,
        ]);
    }
}

/**
 * Extrai código postal de uma linha de endereço (mesma ordem que extractPostalCodeFromLine na extensão).
 */
function minerador_extract_postal_code_from_address(string $text): string
{
    $raw = trim(preg_replace('/\s+/u', ' ', $text));
    if ($raw === '') {
        return '';
    }
    if (preg_match('/\b(\d{5}-\d{4})\b/u', $raw, $m)) {
        return mb_substr($m[1], 0, 16, 'UTF-8');
    }
    if (preg_match('/\b(\d{5}-\d{3})\b/u', $raw, $m)) {
        return mb_substr($m[1], 0, 16, 'UTF-8');
    }
    if (preg_match('/\b(\d{4}-\d{3})\b/u', $raw, $m)) {
        return mb_substr($m[1], 0, 16, 'UTF-8');
    }
    if (preg_match('/\b([A-Z]\d[A-Z]\s?\d[A-Z]\d)\b/ui', $raw, $m)) {
        $ca = strtoupper(preg_replace('/\s+/u', '', $m[1]));
        if (strlen($ca) === 6) {
            return mb_substr(substr($ca, 0, 3) . ' ' . substr($ca, 3), 0, 16, 'UTF-8');
        }

        return mb_substr(strtoupper($m[1]), 0, 16, 'UTF-8');
    }
    if (preg_match('/\b(GIR\s*0AA|[A-Z]{1,2}\d[A-Z0-9]?\s*\d[ABD-HJLNP-UW-Z]{2})\b/ui', $raw, $m)) {
        return mb_substr(strtoupper(preg_replace('/\s+/u', ' ', $m[1])), 0, 16, 'UTF-8');
    }
    if (preg_match('/\b([A-Z]\d{2}\s?[A-Z0-9]{4})\b/ui', $raw, $m)) {
        $ie = strtoupper(preg_replace('/\s+/u', '', $m[1]));
        if (strlen($ie) === 7) {
            return mb_substr(substr($ie, 0, 3) . ' ' . substr($ie, 3), 0, 16, 'UTF-8');
        }

        return mb_substr($ie, 0, 16, 'UTF-8');
    }
    if (preg_match('/\b(\d{8})\b/u', $raw, $m)) {
        return mb_substr(preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $m[1]), 0, 16, 'UTF-8');
    }
    if (preg_match('/\b(\d{5})\b/u', $raw, $m)) {
        return mb_substr($m[1], 0, 16, 'UTF-8');
    }

    return '';
}

/**
 * @param array<string, mixed> $lead
 */
function minerador_cep_from_lead_payload(array $lead): string
{
    $cep = trim((string) ($lead['cep'] ?? ''));
    if ($cep !== '') {
        return mb_substr($cep, 0, 16, 'UTF-8');
    }

    return minerador_extract_postal_code_from_address((string) ($lead['endereco_completo'] ?? ''));
}

/**
 * Insert or detect duplicate for one lead row (same rules as legacy single POST).
 *
 * @param array<string, mixed> $lead
 * @param list<array{substr: string, nivel: string}> $qualificacaoRules
 * @param list<string> $ignoreTerms
 * @return array{id?: int, duplicate: bool, search_id: int, ignored?: bool}|array{error: string, duplicate: bool}
 */
function minerador_process_one_lead(PDO $pdo, array $lead, int $searchId, array $qualificacaoRules, array $ignoreTerms = []): array
{
    if ($ignoreTerms !== [] && minerador_lead_payload_matches_ignore_terms($lead, $ignoreTerms)) {
        return ['ignored' => true, 'duplicate' => false, 'search_id' => $searchId];
    }

    $nome = trim((string) ($lead['nome'] ?? ''));
    $endereco = trim((string) ($lead['endereco_completo'] ?? ''));
    $urlRes = trim((string) ($lead['url_resultado'] ?? ''));
    $hash = minerador_lead_hash($nome, $endereco, $urlRes);

    $keyword = mb_substr(trim((string) ($lead['keyword'] ?? '')), 0, 255, 'UTF-8');
    $queryText = mb_substr(trim((string) ($lead['query'] ?? '')), 0, 1024, 'UTF-8');

    $sel = $pdo->prepare('SELECT id FROM minerador_leads WHERE search_id = ? AND lead_hash = ? LIMIT 1');
    $sel->execute([$searchId, $hash]);
    $existing = $sel->fetchColumn();
    if ($existing !== false) {
        return ['id' => (int) $existing, 'duplicate' => true, 'search_id' => $searchId];
    }

    $nota = $lead['nota'] ?? null;
    $notaSql = null;
    if ($nota !== null && $nota !== '') {
        if (is_string($nota)) {
            $notaSql = (float) str_replace(',', '.', $nota);
        } elseif (is_numeric($nota)) {
            $notaSql = (float) $nota;
        }
    }

    $total = $lead['rate_num'] ?? $lead['total_avaliacoes'] ?? null;
    $totalSql = null;
    if ($total !== null && $total !== '' && is_numeric($total)) {
        $totalSql = (int) $total;
    }

    $telefones = $lead['telefones'] ?? [];
    if (!is_array($telefones)) {
        $telefones = [];
    }
    $telefones = array_values(array_filter(array_map('strval', $telefones)));

    $coletado = (string) ($lead['coletado_em'] ?? '');
    if ($coletado === '') {
        $coletadoDt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    } else {
        try {
            $dt = new DateTimeImmutable($coletado);
            $coletadoDt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            $coletadoDt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
    }

    $mediasNorm = minerador_normalize_medias_from_lead($lead);
    $mediasJson = $mediasNorm === []
        ? null
        : json_encode($mediasNorm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $websiteVal = mb_substr((string) ($lead['website'] ?? ''), 0, 2048, 'UTF-8');
    $mapurlSql = minerador_normalize_mapurl_for_db(
        isset($lead['mapurl']) && is_string($lead['mapurl']) ? $lead['mapurl'] : null
    );
    $qualificacao = minerador_qualificacao_auto($websiteVal, $qualificacaoRules);
    $comentariosSql = minerador_normalize_comentarios_for_db($lead['comentarios'] ?? null);

    $ins = $pdo->prepare(
        'INSERT INTO minerador_leads (
            search_id, keyword, lead_hash, nome, nota, rate_num, categoria,
            endereco_completo, cidade, estado, pais, cep, website, mapurl, phones,
            qualificacao, comentarios,
            medias,
            query_text, pagina, url_resultado, coletado_em
        ) VALUES (
            :search_id, :keyword, :lead_hash, :nome, :nota, :rate_num, :categoria,
            :endereco_completo, :cidade, :estado, :pais, :cep, :website, :mapurl, :phones,
            :qualificacao, :comentarios,
            :medias,
            :query_text, :pagina, :url_resultado, :coletado_em
        )'
    );

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) {
        $pdo->beginTransaction();
    }
    try {
        $ins->execute([
            ':search_id' => $searchId,
            ':keyword' => $keyword,
            ':lead_hash' => $hash,
            ':nome' => $nome,
            ':nota' => $notaSql,
            ':rate_num' => $totalSql,
            ':categoria' => mb_substr((string) ($lead['categoria'] ?? ''), 0, 255, 'UTF-8'),
            ':endereco_completo' => $endereco,
            ':cidade' => mb_substr((string) ($lead['cidade'] ?? ''), 0, 255, 'UTF-8'),
            ':estado' => mb_substr(trim((string) ($lead['estado'] ?? $lead['uf'] ?? '')), 0, 64, 'UTF-8'),
            ':pais' => mb_substr(trim((string) ($lead['pais'] ?? '')), 0, 128, 'UTF-8'),
            ':cep' => minerador_cep_from_lead_payload($lead),
            ':website' => $websiteVal,
            ':mapurl' => $mapurlSql,
            ':phones' => json_encode($telefones, JSON_UNESCAPED_UNICODE),
            ':qualificacao' => $qualificacao,
            ':comentarios' => $comentariosSql,
            ':medias' => $mediasJson,
            ':query_text' => $queryText,
            ':pagina' => max(1, (int) ($lead['pagina'] ?? 1)),
            ':url_resultado' => mb_substr((string) ($lead['url_resultado'] ?? ''), 0, 2048, 'UTF-8'),
            ':coletado_em' => $coletadoDt,
        ]);

        $id = (int) $pdo->lastInsertId();

        minerador_insert_gallery_rows($pdo, $id, $mediasNorm);

        if ($ownTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['id' => $id, 'duplicate' => false, 'search_id' => $searchId];
}

try {
    $cfg = minerador_config();
    $cfgToken = (string) ($cfg['minerador_token'] ?? '');
    $cfgTokenBad = ($cfgToken === '' || $cfgToken === 'ALTERE_ESTE_TOKEN_LONGO');

    $hdr = $_SERVER['HTTP_X_MINERADOR_TOKEN'] ?? '';
    $body = read_json_body();
    $token = is_string($hdr) && $hdr !== '' ? trim($hdr) : trim((string) ($body['token'] ?? ''));
    if ($token === '') {
        respond(false, ['error' => 'Unauthorized'], 401);
    }

    $pdo = minerador_pdo();

    $ownerKey = '';
    $ownerUserId = null;
    if (!$cfgTokenBad && hash_equals($cfgToken, $token)) {
        $ownerKey = 'cfg';
        $ownerUserId = null;
    } else {
        $stU = $pdo->prepare('SELECT id FROM minerador_users WHERE is_active = 1 AND minerador_token = ? LIMIT 1');
        $stU->execute([$token]);
        $uid = (int) $stU->fetchColumn();
        if ($uid <= 0) {
            respond(false, ['error' => 'Unauthorized'], 401);
        }
        $ownerKey = 'u:' . $uid;
        $ownerUserId = $uid;
    }

    if (array_key_exists('leads', $body) && is_array($body['leads'])) {
        $searchId = minerador_upsert_search($pdo, $body, $ownerKey, $ownerUserId);
        $qualificacaoRules = minerador_settings_get_qualificacao_website_rules($pdo);
        $ignoreTerms = minerador_settings_get_leads_ignore_terms($pdo);
        $results = [];
        $pdo->beginTransaction();
        try {
            foreach ($body['leads'] as $item) {
                if (!is_array($item)) {
                    $results[] = ['error' => 'invalid_lead', 'duplicate' => false];
                    continue;
                }
                $results[] = minerador_process_one_lead($pdo, $item, $searchId, $qualificacaoRules, $ignoreTerms);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        respond(true, [
            'batch' => true,
            'results' => $results,
            'count' => count($results),
            'search_id' => $searchId,
        ]);
    }

    $searchId = minerador_upsert_search($pdo, $body, $ownerKey, $ownerUserId);
    $qualificacaoRules = minerador_settings_get_qualificacao_website_rules($pdo);
    $ignoreTerms = minerador_settings_get_leads_ignore_terms($pdo);
    $out = minerador_process_one_lead($pdo, $body, $searchId, $qualificacaoRules, $ignoreTerms);
    respond(true, $out);
} catch (Throwable $e) {
    error_log(sprintf(
        '[minerador datacollect] %s @ %s:%d',
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ) . "\n" . $e->getTraceAsString());
    respond(false, ['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}

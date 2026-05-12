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
function minerador_upsert_search(PDO $pdo, array $meta): int
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
        'INSERT INTO minerador_searches (slug, keyword, localizacao, query_text)
         VALUES (:slug, :keyword, :localizacao, :query_text)
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
    );
    $insSearch->execute([
        ':slug' => $searchSlug,
        ':keyword' => $keyword,
        ':localizacao' => $localizacao,
        ':query_text' => $queryText,
    ]);
    $searchId = (int) $pdo->lastInsertId();
    if ($searchId === 0) {
        $selS = $pdo->prepare('SELECT id FROM minerador_searches WHERE slug = ? LIMIT 1');
        $selS->execute([$searchSlug]);
        $searchId = (int) $selS->fetchColumn();
    }

    return $searchId;
}

/**
 * Insert or detect duplicate for one lead row (same rules as legacy single POST).
 *
 * @param array<string, mixed> $lead
 * @return array{id?: int, duplicate: bool, search_id: int}|array{error: string, duplicate: bool}
 */
function minerador_process_one_lead(PDO $pdo, array $lead, int $searchId): array
{
    $nome = trim((string) ($lead['nome'] ?? ''));
    $endereco = trim((string) ($lead['endereco_completo'] ?? ''));
    $urlRes = trim((string) ($lead['url_resultado'] ?? ''));
    $hash = minerador_lead_hash($nome, $endereco, $urlRes);

    $keyword = mb_substr(trim((string) ($lead['keyword'] ?? '')), 0, 255, 'UTF-8');
    $localizacao = mb_substr(trim((string) ($lead['localizacao'] ?? '')), 0, 255, 'UTF-8');
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

    $total = $lead['total_avaliacoes'] ?? null;
    $totalSql = null;
    if ($total !== null && $total !== '' && is_numeric($total)) {
        $totalSql = (int) $total;
    }

    $telefones = $lead['telefones'] ?? [];
    if (!is_array($telefones)) {
        $telefones = [];
    }
    $telefones = array_values(array_filter(array_map('strval', $telefones)));

    $addweb = ((string) ($lead['addweb'] ?? 'nao')) === 'sim' ? 'sim' : 'nao';

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

    $ins = $pdo->prepare(
        'INSERT INTO minerador_leads (
            search_id, keyword, localizacao, lead_hash, nome, nota, total_avaliacoes, categoria,
            endereco_completo, cidade, uf, cep, website, telefones_json, addweb, aba_sobre_html,
            query_text, pagina, url_resultado, coletado_em
        ) VALUES (
            :search_id, :keyword, :localizacao, :lead_hash, :nome, :nota, :total_avaliacoes, :categoria,
            :endereco_completo, :cidade, :uf, :cep, :website, :telefones_json, :addweb, :aba_sobre_html,
            :query_text, :pagina, :url_resultado, :coletado_em
        )'
    );

    $ins->execute([
        ':search_id' => $searchId,
        ':keyword' => $keyword,
        ':localizacao' => $localizacao,
        ':lead_hash' => $hash,
        ':nome' => $nome,
        ':nota' => $notaSql,
        ':total_avaliacoes' => $totalSql,
        ':categoria' => mb_substr((string) ($lead['categoria'] ?? ''), 0, 255, 'UTF-8'),
        ':endereco_completo' => $endereco,
        ':cidade' => mb_substr((string) ($lead['cidade'] ?? ''), 0, 255, 'UTF-8'),
        ':uf' => mb_strtoupper(mb_substr((string) ($lead['uf'] ?? ''), 0, 2, 'UTF-8'), 'UTF-8'),
        ':cep' => mb_substr((string) ($lead['cep'] ?? ''), 0, 16, 'UTF-8'),
        ':website' => mb_substr((string) ($lead['website'] ?? ''), 0, 2048, 'UTF-8'),
        ':telefones_json' => json_encode($telefones, JSON_UNESCAPED_UNICODE),
        ':addweb' => $addweb,
        ':aba_sobre_html' => (string) ($lead['aba_sobre_html'] ?? ''),
        ':query_text' => $queryText,
        ':pagina' => max(1, (int) ($lead['pagina'] ?? 1)),
        ':url_resultado' => mb_substr((string) ($lead['url_resultado'] ?? ''), 0, 2048, 'UTF-8'),
        ':coletado_em' => $coletadoDt,
    ]);

    $id = (int) $pdo->lastInsertId();

    return ['id' => $id, 'duplicate' => false, 'search_id' => $searchId];
}

try {
    $cfg = minerador_config();
    $expected = (string) ($cfg['minerador_token'] ?? '');
    if ($expected === '' || $expected === 'ALTERE_ESTE_TOKEN_LONGO') {
        respond(false, ['error' => 'Servidor sem token configurado corretamente.'], 500);
    }

    $hdr = $_SERVER['HTTP_X_MINERADOR_TOKEN'] ?? '';
    $body = read_json_body();
    $token = is_string($hdr) && $hdr !== '' ? $hdr : (string) ($body['token'] ?? '');
    if (!hash_equals($expected, $token)) {
        respond(false, ['error' => 'Unauthorized'], 401);
    }

    $pdo = minerador_pdo();

    if (array_key_exists('leads', $body) && is_array($body['leads'])) {
        $searchId = minerador_upsert_search($pdo, $body);
        $results = [];
        $pdo->beginTransaction();
        try {
            foreach ($body['leads'] as $item) {
                if (!is_array($item)) {
                    $results[] = ['error' => 'invalid_lead', 'duplicate' => false];
                    continue;
                }
                $results[] = minerador_process_one_lead($pdo, $item, $searchId);
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

    $searchId = minerador_upsert_search($pdo, $body);
    $out = minerador_process_one_lead($pdo, $body, $searchId);
    respond(true, $out);
} catch (Throwable $e) {
    respond(false, ['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}

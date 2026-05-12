<?php
declare(strict_types=1);

/**
 * Conexão PDO compartilhada (datacollect + admin).
 */
function minerador_config(): array
{
    $path = __DIR__ . '/config.php';
    if (!is_readable($path)) {
        throw new RuntimeException('Arquivo config.php não encontrado. Copie config.example.php para config.php.');
    }
    /** @var array $cfg */
    $cfg = require $path;
    return $cfg;
}

function minerador_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = minerador_config();
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $c['db_host'],
        $c['db_name'],
        $c['db_charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function minerador_lead_hash(string $nome, string $endereco, string $urlResultado = ''): string
{
    $n = mb_strtolower(trim($nome), 'UTF-8');
    $e = mb_strtolower(trim($endereco), 'UTF-8');
    $u = mb_strtolower(trim($urlResultado), 'UTF-8');
    return hash('sha256', $n . '|' . $e . '|' . $u);
}

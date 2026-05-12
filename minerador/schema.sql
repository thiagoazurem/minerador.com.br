-- Minerador: schema MySQL (phpMyAdmin)
-- Charset recomendado para o banco: utf8mb4_unicode_ci

-- Cada execução do popup vira uma "busca" identificada por slug curto.
CREATE TABLE IF NOT EXISTS `minerador_searches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` VARCHAR(64) NOT NULL,
  `keyword` VARCHAR(255) NOT NULL DEFAULT '',
  `localizacao` VARCHAR(255) NOT NULL DEFAULT '',
  `query_text` VARCHAR(1024) NOT NULL DEFAULT '',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `minerador_leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `search_id` BIGINT UNSIGNED NULL,
  `keyword` VARCHAR(255) NOT NULL DEFAULT '',
  `localizacao` VARCHAR(255) NOT NULL DEFAULT '',
  `lead_hash` CHAR(64) NOT NULL,
  `nome` VARCHAR(512) NOT NULL DEFAULT '',
  `nota` DECIMAL(3,1) NULL,
  `total_avaliacoes` INT UNSIGNED NULL,
  `categoria` VARCHAR(255) NOT NULL DEFAULT '',
  `endereco_completo` TEXT NULL,
  `cidade` VARCHAR(255) NOT NULL DEFAULT '',
  `uf` CHAR(2) NOT NULL DEFAULT '',
  `cep` VARCHAR(16) NOT NULL DEFAULT '',
  `website` VARCHAR(2048) NOT NULL DEFAULT '',
  `telefones_json` JSON NULL,
  `addweb` ENUM('sim','nao') NOT NULL DEFAULT 'nao',
  `aba_sobre_html` MEDIUMTEXT NULL,
  `query_text` VARCHAR(1024) NOT NULL DEFAULT '',
  `pagina` INT UNSIGNED NOT NULL DEFAULT 1,
  `url_resultado` VARCHAR(2048) NOT NULL DEFAULT '',
  `coletado_em` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_lead_hash_search` (`search_id`, `lead_hash`),
  KEY `idx_search_id` (`search_id`),
  KEY `idx_coletado_em` (`coletado_em`),
  KEY `idx_query_text` (`query_text`(191)),
  KEY `idx_cidade_uf` (`cidade`, `uf`),
  KEY `idx_addweb` (`addweb`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: adicionar minerador_searches e relacionar leads por busca.
-- Rodar UMA VEZ no phpMyAdmin. Seguro porque o banco ainda está vazio.

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

ALTER TABLE `minerador_leads`
  ADD COLUMN `search_id` BIGINT UNSIGNED NULL AFTER `id`,
  ADD COLUMN `keyword` VARCHAR(255) NOT NULL DEFAULT '' AFTER `search_id`,
  ADD COLUMN `localizacao` VARCHAR(255) NOT NULL DEFAULT '' AFTER `keyword`,
  ADD INDEX `idx_search_id` (`search_id`);

ALTER TABLE `minerador_leads` DROP INDEX `uk_lead_hash`;
ALTER TABLE `minerador_leads` ADD UNIQUE KEY `uk_lead_hash_search` (`search_id`, `lead_hash`);

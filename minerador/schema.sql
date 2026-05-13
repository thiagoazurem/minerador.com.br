-- Minerador: schema MySQL (phpMyAdmin)

-- Charset recomendado para o banco: utf8mb4_unicode_ci



-- Utilizadores delegados do painel (admin continua em config.php).

CREATE TABLE IF NOT EXISTS `minerador_users` (

  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `username` VARCHAR(64) NOT NULL,

  `password_hash` VARCHAR(255) NOT NULL,

  `minerador_token` VARCHAR(128) NOT NULL,

  `is_active` TINYINT(1) NOT NULL DEFAULT 1,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uk_username` (`username`),

  UNIQUE KEY `uk_minerador_token` (`minerador_token`),

  KEY `idx_active` (`is_active`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS `minerador_settings` (

  `setting_key` VARCHAR(64) NOT NULL,

  `setting_value` MEDIUMTEXT NOT NULL,

  PRIMARY KEY (`setting_key`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Cada execução do popup vira uma "busca"; slug único por dono (owner_key = cfg para token do config).

CREATE TABLE IF NOT EXISTS `minerador_searches` (

  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `slug` VARCHAR(64) NOT NULL,

  `keyword` VARCHAR(255) NOT NULL DEFAULT '',

  `localizacao` VARCHAR(255) NOT NULL DEFAULT '',

  `query_text` VARCHAR(1024) NOT NULL DEFAULT '',

  `owner_key` VARCHAR(64) NOT NULL DEFAULT 'cfg',

  `owner_user_id` BIGINT UNSIGNED NULL,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uk_owner_key_slug` (`owner_key`, `slug`),

  KEY `idx_created_at` (`created_at`),

  KEY `idx_owner_user` (`owner_user_id`),

  CONSTRAINT `fk_minerador_searches_owner`

    FOREIGN KEY (`owner_user_id`) REFERENCES `minerador_users` (`id`)

    ON DELETE RESTRICT

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS `minerador_leads` (

  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `search_id` BIGINT UNSIGNED NULL,

  `keyword` VARCHAR(255) NOT NULL DEFAULT '',

  `lead_hash` CHAR(64) NOT NULL,

  `nome` VARCHAR(512) NOT NULL DEFAULT '',

  `nota` DECIMAL(3,1) NULL,

  `rate_num` INT UNSIGNED NULL,

  `categoria` VARCHAR(255) NOT NULL DEFAULT '',

  `endereco_completo` TEXT NULL,

  `cidade` VARCHAR(255) NOT NULL DEFAULT '',

  `estado` VARCHAR(64) NOT NULL DEFAULT '',

  `pais` VARCHAR(128) NOT NULL DEFAULT '',

  `cep` VARCHAR(16) NOT NULL DEFAULT '',

  `website` VARCHAR(2048) NOT NULL DEFAULT '',

  `mapurl` TEXT NULL,

  `phones` JSON NULL,

  `qualificacao` ENUM('baixo','medio','alto','max') NULL DEFAULT NULL,

  `comentarios` TEXT NULL,

  `medias` JSON NULL,

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

  KEY `idx_cidade_estado` (`cidade`, `estado`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS `minerador_gallery` (

  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

  `lead_id` BIGINT UNSIGNED NOT NULL,

  `kind` ENUM('image','video') NOT NULL,

  `url` TEXT NOT NULL,

  `thumb_url` TEXT NULL,

  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  KEY `idx_lead_sort` (`lead_id`, `sort_order`),

  CONSTRAINT `fk_minerador_gallery_lead`

    FOREIGN KEY (`lead_id`) REFERENCES `minerador_leads` (`id`)

    ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


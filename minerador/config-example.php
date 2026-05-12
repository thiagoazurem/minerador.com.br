<?php
/**
 * Copie este arquivo para `config.php` e preencha com seus dados reais.
 * NUNCA commite `config.php` com segredos em repositório público.
 */
return [
    'db_host' => 'localhost',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'db_charset' => 'utf8mb4',
    /** Token compartilhado com a extensão (header X-Minerador-Token) */
    'minerador_token' => 'teste123',
    /** Credenciais do painel admin */
    'admin_user' => 'admin',
    /** Gere com: php -r "echo password_hash('sua_senha', PASSWORD_DEFAULT), PHP_EOL;" */
    'admin_pass_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // senha: "password" (troque!)
];

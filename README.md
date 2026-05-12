# Minerador Google Local (Chrome + PHP + MySQL)

Extensão Chrome (Manifest V3) que automatiza a busca local do Google (`udm=1`), abre cada resultado, extrai dados do painel (`.immersive-container` ou, na ausência deste, `#local-place-viewer`) e envia **um lead por vez** (aguarda a resposta de `datacollect.php` antes do próximo e antes da paginação). Inclui painel admin em PHP.

## 1. Banco de dados (phpMyAdmin)

1. Crie um banco (ex.: `minerador_db`).
2. Importe [`minerador/schema.sql`](minerador/schema.sql).

## 2. Configuração PHP no servidor

1. Copie `minerador/config.example.php` para **`minerador/config.php`** (não commite segredos).
2. Ajuste:
   - `db_*` (host, nome do banco, usuário, senha)
   - `minerador_token` — mesmo valor usado na extensão (header `X-Minerador-Token`)
   - `admin_user` e `admin_pass_hash` — hash gerado com:
     ```bash
     php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT), PHP_EOL;"
     ```
3. O exemplo de `admin_pass_hash` no `config.example.php` corresponde à senha **`password`** — **altere em produção**.

Arquivos públicos esperados no host:

- `https://SEU-DOMINIO/minerador/datacollect.php`
- `https://SEU-DOMINIO/minerador/admin/` (login, dashboard, export)

## 3. Extensão Chrome

1. Abra `chrome://extensions` → **Modo do desenvolvedor** → **Carregar sem compactação**.
2. Selecione a pasta [`extension/`](extension/).
3. No popup da extensão, preencha palavra-chave, localização, URL de `datacollect.php` e o **token**.
4. Clique **Iniciar** — a primeira aba Google encontrada será redirecionada para `https://www.google.com/webhp?udm=1&hl=pt-BR&gl=br` e a busca será disparada.

**Permissões:** a extensão precisa de acesso a `google.com` / `google.com.br` e ao host do `datacollect.php` (já declarados no `manifest.json`; ajuste se usar outro domínio).

**Captcha:** se o Google exibir reCAPTCHA / “não sou um robô”, a extensão pausa e o service worker usa **TTS** (`chrome.tts`) para avisar em voz. Resolva manualmente e use **Continuar** no popup.

## 4. Admin (dashboard)

Acesse `https://SEU-DOMINIO/minerador/admin/login.php`, faça login e use:

- Filtros, ordenação, paginação
- **Exportar CSV** (respeita filtros atuais)
- **Ver** detalhe do lead (HTML da aba “Sobre” em `iframe` com `sandbox=""`)

## 5. Estrutura do repositório

```
extension/
  manifest.json
  background/service-worker.js
  popup/
  content/google-local-scraper.js
minerador/
  bootstrap_db.php
  config.example.php   → copiar para config.php
  datacollect.php
  schema.sql
  admin/
    _bootstrap.php
    login.php
    index.php
    lead.php
    export.php
    logout.php
```

## 6. Aviso legal / uso

A automação pode violar os Termos de Serviço do Google e gerar bloqueios. Use por sua conta e risco, com moderação e apenas em cenários autorizados.

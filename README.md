# Minerador Google Local (Chrome + PHP + MySQL)

Extensão Chrome (Manifest V3) que automatiza a busca local do Google (`udm=1`), abre cada resultado, extrai dados do painel (`.immersive-container` ou, na ausência deste, `#local-place-viewer`) e, **por cada página de resultados na SERP**, acumula os leads extraídos e envia **um POST em lote** para `datacollect.php` (JSON com array `leads`; o servidor responde com `batch: true`). Depois avança para a paginação seguinte. Inclui painel admin em PHP.

## 1. Banco de dados (phpMyAdmin)

1. Crie um banco (ex.: `minerador_db`).
2. Importe [`minerador/schema.sql`](minerador/schema.sql). Este ficheiro define o esquema atual, incluindo utilizadores delegados (`minerador_users`), definições (`minerador_settings`), dono das buscas (`owner_key` / `owner_user_id` em `minerador_searches`), leads, galeria, etc.
3. Se já tinha uma base criada com um `schema.sql` mais antigo, alinhe manualmente a estrutura ao ficheiro atual (compare tabelas/colunas com o `schema.sql` do repositório ou faça backup e recrie). **Não** há scripts `migration_*.sql` neste repositório.

## 2. Configuração PHP no servidor

1. Copie `minerador/config-example.php` para **`minerador/config.php`** (não commite segredos).
2. Ajuste:
   - `db_*` (host, nome do banco, utilizador, senha)
   - `minerador_token` — token da **extensão do administrador** (header `X-Minerador-Token`). Continua a ser definido **apenas** no `config.php` (não é guardado na base para o admin).
   - `admin_user` e `admin_pass_hash` — credenciais do **administrador** (também só no `config.php`); hash gerado com:
     ```bash
     php -r "echo password_hash('SUA_SENHA', PASSWORD_DEFAULT), PHP_EOL;"
     ```
3. O exemplo de `admin_pass_hash` no `config-example.php` corresponde à senha **`password`** — **altere em produção**.
4. **Utilizadores delegados:** após login como admin, abra **Utilizadores** e crie contas. Cada uma tem o seu `minerador_token` na base (visível só ao admin) para configurar na extensão desse utilizador. A API `datacollect.php` aceita o token do `config.php` (buscas com dono “config”) ou o token de um utilizador ativo na tabela `minerador_users`.

Arquivos públicos esperados no host:

- `https://SEU-DOMINIO/minerador/datacollect.php`
- `https://SEU-DOMINIO/minerador/admin/` (login com captcha por emoji, dashboard, leads, buscas, definições, export, utilizadores, etc.)

## 3. Extensão Chrome

1. Abra `chrome://extensions` → **Modo do desenvolvedor** → **Carregar sem compactação**.
2. Selecione a pasta [`extension/`](extension/).
3. No popup da extensão, preencha palavra-chave, localização, URL de `datacollect.php` e o **token** (o do `config.php` para o admin, ou o token mostrado em **Utilizadores** para um utilizador delegado).
4. Clique **Iniciar** — a primeira aba Google encontrada será redirecionada para `https://www.google.com/webhp?udm=1&hl=pt-BR&gl=br` e a busca será disparada. Os leads coletados na página atual são enviados em **lote** ao servidor quando termina o processamento dessa página (antes de mudar de página na SERP).

**Permissões:** a extensão precisa de acesso a `google.com` / `google.com.br` e ao host do `datacollect.php` (já declarados no `manifest.json`; ajuste se usar outro domínio).

**Captcha:** se o Google exibir reCAPTCHA / “não sou um robô”, a extensão pausa e o service worker usa **TTS** (`chrome.tts`) para avisar em voz. Resolva manualmente e use **Continuar** no popup.

## 4. Admin (dashboard)

Acesse `https://SEU-DOMINIO/minerador/admin/login.php`, resolva o **captcha** (descrever o emoji em português), faça login com o utilizador do `config.php` ou com um utilizador delegado, e use:

- Filtros, ordenação, paginação (cada utilizador delegado vê só as buscas/leads do seu token)
- **Administrador:** alternância **Todos** / **Só token do admin** (`scope=mine`) para filtrar apenas dados ingeridos com o `minerador_token` do `config.php` ou ver tudo no servidor; **Utilizadores** para CRUD e tokens
- **Leads** — listagem com filtros e eliminação por filtro (formulário dedicado)
- **Buscas** — lista de execuções, eliminar busca, transferir dono da busca (e leads associados) para o token admin ou delegado
- **Definições** — opções do servidor (ex.: termos a ignorar em leads, limpeza de dados); disponível conforme permissões
- **Exportar CSV** (respeita filtros e escopo)
- **Ver** detalhe do lead (pré-visualização do site, campo “Lead potencial”, etc.) e alteração de dados quando aplicável

## 5. Estrutura do repositório

```
extension/
  manifest.json
  background/service-worker.js
  popup/
  content/google-local-scraper.js
minerador/
  bootstrap_db.php
  config-example.php   → copiar para config.php
  datacollect.php
  schema.sql
  admin/
    _bootstrap.php
    _nav.php
    captcha_challenges.php
    login.php
    logout.php
    index.php
    leads.php
    leads_delete_by_filter.php
    searches.php
    search_delete.php
    search_transfer_save.php
    settings.php
    settings_save.php
    lead.php
    lead_save.php
    lead_delete.php
    export.php
    users.php
    user_save.php
    user_delete.php
    password_change_save.php
```

## 6. Aviso legal / uso

A automação pode violar os Termos de Serviço do Google e gerar bloqueios. Use por sua conta e risco, com moderação e apenas em cenários autorizados.

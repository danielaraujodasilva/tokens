# Tokens

App PHP em arquivo unico para estimar consumo antes de rodar tarefas no Codex.

## Webhook GitHub

- Payload URL: `https://seudominio.com/tokens/api/github_webhook.php`
- Content type: `application/json`
- Secret: use o valor de `DEPLOY_WEBHOOK_SECRET` configurado em `config/local.php` no servidor.
- SSL verification: `Enable SSL verification`
- Events: `Just the push event`
- Active: marcado

O webhook atualiza o diretorio `C:\xampp\htdocs\site\tokens` com `git fetch` e `git pull --ff-only` na branch `main`.

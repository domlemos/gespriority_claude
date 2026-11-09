# Correção de segurança — PHP2Shell e dependências (outubro/2026)

**Status:** corrigido em produção em 27/10/2026 (commit `e88b289`).

## Resumo

Em 21/10/2026 o PHP publicou correções de emergência para duas falhas
críticas, apelidadas de **PHP2Shell**: CVE-2026-109618 e
CVE-2026-109619 (CVSS 9.2). Elas permitem **execução remota de código sem
autenticação** em servidores PHP-FPM, mod_php, php-cgi, FrankenPHP e
LiteSpeed, e já estavam sendo exploradas ativamente desde 17/10, antes da
divulgação.

A produção do upITSM rodava **PHP 8.3.35 com PHP-FPM**, versão afetada
(a correção é a 8.3.36). Não há evidência de exploração; a verificação
foi feita após a divulgação e a correção aplicada em seguida.

## Causa de a produção não ter recebido a correção

A imagem oficial `php:8.3-fpm-alpine` já trazia o 8.3.36 desde 21/10, mas
o deploy automático montava a imagem com `docker compose build` **sem
`--pull`**. O servidor reaproveitava indefinidamente a imagem base que já
tinha em cache, então nenhum deploy trazia atualizações de segurança do
PHP — só um rebuild manual com pull resolveria.

## O que foi feito

| Item | Antes | Depois |
|---|---|---|
| PHP em produção (`itsm-app`, `itsm-queue`) | 8.3.35 (vulnerável) | **8.3.36** |
| Build no deploy ([`deploy-ec2.yml`](../../.github/workflows/deploy-ec2.yml)) | `build app` (base em cache) | `build --pull app` (base sempre atualizada) |
| `session.upload_progress.enabled` ([`security.ini`](../../docker/php-fpm/conf.d/security.ini)) | `1` | `0` |
| Alertas do `composer audit` | 7 alertas em 4 pacotes | nenhum |

- **`--pull` no deploy:** daqui para frente, correções do PHP 8.3 publicadas
  na imagem oficial entram em produção no próximo deploy, sem ação manual.
- **`session.upload_progress` desligado:** mitigação oficial para o
  CVE-2026-109619 e para o **CVE-2026-115560**, divulgado em 23/10 e ainda
  **sem correção** lançada. A aplicação não usa esse recurso (a API
  autentica com tokens Sanctum, sem sessão PHP), então não há efeito
  colateral. Pode ser mantido mesmo depois da correção.
- **Pacotes PHP atualizados** (só versões menores e de correção):

| Pacote | De → para | Alerta corrigido |
|---|---|---|
| `laravel/framework` | 13.19.0 → 13.39.0 | Bypass de autenticação no `TokenGuard` (crítico — **não** afetava o projeto, que usa Sanctum); exclusão indevida via inclusão de relacionamentos (médio); XSS na página de debug (baixo) |
| `symfony/http-foundation` | 7.4.14 → 7.4.21 | CVE-2026-108082: negação de serviço (ReDoS) pelo cabeçalho `Cache-Control`, disparável sem login |
| `league/commonmark` | 2.10.0 → 2.10.4 | Negação de serviço em tabelas GFM (alto); bypass de `DisallowedRawHtml` (médio) |
| `league/flysystem` | 3.35.2 → 3.36.0 | Bypass da checagem de caracteres de controle em caminhos (baixo) |

O Guzzle foi mantido na versão principal 7 de propósito, para limitar o
escopo da atualização.

## Verificação

- Suíte de testes: 464/464 passando com os pacotes atualizados.
- Imagem de produção montada localmente com `--pull`: PHP 8.3.36 e
  `session.upload_progress` desligado.
- Após o deploy, conferido nos containers de produção via SSM:
  `itsm-app` e `itsm-queue` em **PHP 8.3.36** (`php-fpm -v` também), com
  `session.upload_progress.enabled` desligado; API respondendo normalmente.
- `composer audit --locked`: nenhum alerta.

## Atualização de 09/11/2026 — novos alertas em dependências

Uma nova auditoria encontrou alertas publicados depois da correção acima,
no back (12 alertas em 6 pacotes) e no front (7 alertas altos). Todos foram
corrigidos com versões menores e de correção, sem mudança de versão
principal:

| Pacote | De → para | Alertas |
|---|---|---|
| `phpoffice/phpspreadsheet` (via `maatwebsite/excel` 4.0.1 → 4.0.6) | 5.9.0 → 5.11.0 | XXE e negação de serviço na **leitura** de planilhas, SSRF ao carregar imagens externas. O projeto só **exporta** relatórios em Excel, então o risco real era baixo |
| `symfony/http-foundation`, `http-kernel`, `routing` | 7.4.21 → 7.4.23 | Bypass de regras de acesso por caminho/método (`HEAD`), URLs geradas com host escolhido pelo cliente via cabeçalhos encaminhados, proxies confiáveis aceitando endereços IPv4 mapeados |
| `symfony/mime` | 7.4.19 → 7.4.22 | CVE-2026-45070: injeção de cabeçalho em e-mail |
| `symfony/filesystem` | 7.4.17 → 7.4.23 | Caminhos com `://` escapando do diretório base |
| Front: `axios`, `vue`, `vite`, `follow-redirects`, `nanoid`, `source-map-js` | versões corrigidas (`axios` 1.21.0, `vue` 3.5.45, `vite` 8.3.7) | 7 alertas altos. Os do `vite`/`launch-editor` só afetam o servidor de desenvolvimento; `axios` e `vue` vão para o navegador |

Verificação: 499/499 testes do back passando (incluindo exportação de
relatórios), build do front ok, `composer audit --locked` e `npm audit`
sem nenhum alerta.

## Pendências

- **CVE-2026-115560:** acompanhar o próximo release do PHP 8.3. Com o
  `--pull`, basta um deploy depois que a imagem oficial for atualizada.
- **Imagem de desenvolvimento local** ([`docker/php/Dockerfile`](../../docker/php/Dockerfile)):
  usa a tag fixa `dunglas/frankenphp:1.12.4-php8.3-alpine`. Não está
  exposta à internet, mas deve ser atualizada para uma versão com PHP
  ≥ 8.3.36.
- **Workflow antigo de ECS** (`deploy-ecs.yml`, também com FrankenPHP em
  tag fixa): não roda mais sozinho e a infraestrutura que ele mira foi
  desativada; não é afetado, mas não deve ser reativado sem atualizar a
  imagem.

## Como verificar de novo

```bash
# Dependências PHP (back)
composer audit --locked

# Dependências JavaScript (front, em gespriority_claude_front)
npm audit

# Versão do PHP em produção (via SSM, sem SSH)
aws ssm send-command --region us-east-1 \
  --instance-ids i-0246eb93df7075288 \
  --document-name AWS-RunShellScript \
  --parameters 'commands=["docker exec itsm-app php -v | head -1"]'
```

## Referências

- [CISA — Critical Vulnerabilities in PHP Require Immediate Patching](https://www.cisa.gov/news-events/alerts/2026/10/21/critical-vulnerabilities-php-require-immediate-patching)
- [Tenable — php2shell FAQ](https://www.tenable.com/blog/php2shell-cve-2026-109618-cve-2026-109619-frequently-asked-questions-about-critical-php-remote)
- [Datadog Security Labs — PHP2Shell](https://securitylabs.datadoghq.com/articles/php2shell-critical-vulnerabilities-in-php/)
- [Unit 42 — Threat Brief CVE-2026-109618 / CVE-2026-109619](https://unit42.paloaltonetworks.com/cve-2026-109618-cve-2026-109619/)
- [The Hacker News — CVE-2026-115560](https://thehackernews.com/2026/10/urgent-php-warns-of-another-unpatched.html)

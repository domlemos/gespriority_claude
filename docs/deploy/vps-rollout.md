# Rollout alternativo — VPS convencional (nginx + PHP-FPM + PostgreSQL)

Checklist pra hospedar a mesma aplicação num VPS comum (ex.: Hostinger
KVM) em vez de AWS ECS Fargate, sem FrankenPHP/Octane. Ver
[`docs/infra/README.md`](../infra/README.md) pra arquitetura AWS (o
alvo real, que este setup emula) e a conversa que originou este arquivo
pro comparativo completo de trade-offs.

**Não serve pra hospedagem compartilhada** — precisa de acesso root
(Docker, portas 80/443, processo de fila de vida longa), coisa que
plano compartilhado normalmente não permite. Um VPS KVM serve.

## Diferenças de configuração em relação à AWS

Nenhuma mudança de código — só variáveis de ambiente:

| Variável | AWS | VPS |
|---|---|---|
| `FILESYSTEM_DISK` | `s3` | `local` — anexos vão pro disco do próprio VPS; backup deles passa a ser sua responsabilidade (ver seção de backup abaixo) |
| `MAIL_MAILER` | `ses` | `smtp` — usar SMTP do próprio Hostinger ou outro provedor; preencher `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` |
| `OCTANE_SERVER` | `frankenphp` | não se aplica — este setup não roda `php artisan octane:*`, o `docker-compose.vps.yml` sobe PHP-FPM puro atrás do nginx |

## 1. Provisionar o VPS

- VPS com Docker + Docker Compose plugin instalados (`curl -fsSL
  https://get.docker.com | sh`).
- Apontar um registro **A** do domínio (onde quer que ele esteja
  registrado — não precisa ser Route53 aqui) pro IP público do VPS.
  Confirmar propagação com `dig A seu-dominio.com +short` antes de
  seguir — o Certbot falha o desafio HTTP-01 se o DNS ainda não
  resolver pro VPS.

## 2. Preparar o `.env`

```bash
git clone <repo> && cd <repo>
cp .env.example .env
php artisan key:generate   # ou gere fora e cole em APP_KEY
```

Preencher, além do padrão de produção (`APP_ENV=production`,
`APP_DEBUG=false`):

- `DB_HOST=postgres`, `DB_PASSWORD=<senha forte>` (o service `postgres`
  do compose lê essa mesma variável).
- `FILESYSTEM_DISK=local`, `MAIL_MAILER=smtp` + credenciais SMTP (ver
  tabela acima).
- `DOMAIN=seu-dominio.com` e `CERTBOT_EMAIL=voce@seu-dominio.com` —
  usadas pelo `docker-compose.vps.yml`/nginx/Certbot, não pelo Laravel.

## 3. Emitir o certificado TLS (fase de bootstrap)

O nginx recusa subir se `app.conf.template` referenciar um certificado
que ainda não existe — por isso a primeira subida usa uma config
só-HTTP:

```bash
# Aponta o volume do nginx pra config de bootstrap (só porta 80 + desafio ACME)
sed -i 's#app.conf.template#bootstrap.conf.template#' docker-compose.vps.yml

docker compose -f docker-compose.vps.yml up -d postgres app nginx

docker compose -f docker-compose.vps.yml --profile certbot run --rm certbot \
  certonly --webroot -w /var/www/certbot \
  -d "$DOMAIN" --email "$CERTBOT_EMAIL" --agree-tos --no-eff-email

# Volta pro vhost definitivo (HTTP redireciona pra HTTPS, TLS de verdade)
sed -i 's#bootstrap.conf.template#app.conf.template#' docker-compose.vps.yml
docker compose -f docker-compose.vps.yml up -d nginx
```

Confirme com `curl -I https://$DOMAIN` (deve responder, mesmo que com
404/401 da aplicação — o importante aqui é o TLS).

## 4. Rodar a migration antes de expor a fila

Mesmo racional do pipeline AWS (`docs/infra/README.md` — a migration
roda isolada, falha o rollout inteiro antes de tocar nos services de
verdade):

```bash
docker compose -f docker-compose.vps.yml run --rm app php artisan migrate --force
```

Só depois disso, suba o resto:

```bash
docker compose -f docker-compose.vps.yml up -d --build
```

## 5. Dados de referência (protótipo)

Mesma ressalva do rollout AWS: `php artisan db:seed` completo não roda
em produção (usa `fakerphp/faker`, dependência de dev). Rodar só os
seeders de referência manualmente, uma vez:

```bash
docker compose -f docker-compose.vps.yml run --rm app php artisan db:seed --class=<SeederDeReferencia> --force
```

## 6. Validar

```bash
curl -I https://$DOMAIN/up   # espera 200
```

Testar upload/download/delete de um anexo real pra confirmar que
`FILESYSTEM_DISK=local` está funcionando fim a fim (equivalente ao
teste de S3 do rollout AWS).

## 7. Renovação do certificado (cron no host)

Certbot não roda como serviço de fundo aqui (perfil `certbot`, opt-in)
— agendar no `crontab` do próprio VPS:

```cron
0 3 1 * * cd /caminho/do/repo && docker compose -f docker-compose.vps.yml --profile certbot run --rm certbot renew --webroot -w /var/www/certbot && docker compose -f docker-compose.vps.yml exec nginx nginx -s reload
```

## 8. Backup do banco (não é gerenciado como o RDS)

Sem snapshot automático — agendar `pg_dump` no host, ex.:

```cron
0 4 * * * docker compose -f /caminho/do/repo/docker-compose.vps.yml exec -T postgres pg_dump -U "$DB_USERNAME" "$DB_DATABASE" | gzip > /backups/itsm-$(date +\%F).sql.gz
```

Guardar essas cópias fora do próprio VPS (ex.: sincronizar pra um
bucket S3 já existente da conta AWS, ou outro storage externo) — um
backup que só existe no mesmo disco que ele protege não protege contra
falha do disco.

## Deploy de código novo

Sem pipeline automático aqui — nenhum GitHub Actions consegue alcançar
um VPS genérico do jeito que alcança uma instância EC2 via SSM (ver
[`ec2-rollout.md` §7](ec2-rollout.md) pro caso AWS, esse sim
automatizado). Manual:

```bash
git pull
docker compose -f docker-compose.vps.yml up -d --build app queue
```

`opcache.validate_timestamps=0` (ver `docker/php-fpm/conf.d/opcache.ini`)
significa que o container precisa ser **recriado** (não só reiniciado)
a cada deploy pra pegar código novo — `--build` acima já garante isso.

## Rollback

Sem revisão de task definition como no ECS — reverter é dar checkout
no commit anterior e reconstruir:

```bash
git checkout <commit-anterior>
docker compose -f docker-compose.vps.yml up -d --build app queue
```

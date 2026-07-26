FROM dunglas/frankenphp:1.12.4-php8.3-alpine
# Tag fixada explicitamente (nunca `latest`) para builds reprodutíveis.
# Laravel Octane 2.x exige FrankenPHP >= 1.5.0 (checado em runtime via
# `frankenphp build-info`); confirmar esse mínimo ao atualizar a tag.

ARG USER_ID=1000
ARG GROUP_ID=1000

# Composer (não incluído na imagem base) — necessário para `composer install`
# manual dentro do container, conforme seção 1.2 da especificação.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Pacotes de sistema (NÃO são extensões PHP)
RUN apk add --no-cache \
    bash \
    shadow \
    icu-dev \
    postgresql-dev \
    $PHPIZE_DEPS

# Extensões PHP
# pcntl não está listada na especificação original, mas é exigida pelo
# Laravel Octane (InteractsWithServers::getSubscribedSignals) para tratar
# SIGINT/SIGTERM/SIGHUP — sem ela o worker falha ao iniciar.
RUN docker-php-ext-install pdo pdo_pgsql intl pcntl

# Remapeamento de UID/GID do usuário da imagem para o usuário do host
# ⚠️ Confirmado: a imagem base usa `www-data` (uid/gid 82, Alpine).
RUN if getent passwd www-data > /dev/null; then \
        usermod -u ${USER_ID} www-data; \
    else \
        useradd -u ${USER_ID} -m www-data; \
    fi && \
    if getent group www-data > /dev/null; then \
        groupmod -g ${GROUP_ID} www-data; \
    else \
        groupadd -g ${GROUP_ID} www-data; \
    fi

WORKDIR /app

COPY . /app

RUN chown -R www-data:www-data /app /config /data

USER www-data

EXPOSE 8000

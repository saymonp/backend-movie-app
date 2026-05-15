FROM dunglas/frankenphp:php8.4

# Extensões essenciais: pdo_pgsql (DB), pcntl (Worker/Fila), opcache (Velocidade)
RUN install-php-extensions \
    pdo_pgsql \
    pcntl \
    opcache \
    zip \
    intl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Cache de dependências
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache

ENV PORT=8080
EXPOSE 8080


# Comando
CMD ["frankenphp", "php-server", ":$PORT"]
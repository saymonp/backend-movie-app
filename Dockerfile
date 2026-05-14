# Usamos a imagem oficial do FrankenPHP com PHP 8.4
FROM dunglas/frankenphp:1.1-php8.4

# Instalar extensões essenciais via script auxiliar do FrankenPHP
# Removido GD e bcmath. Mantido pdo_pgsql para o banco e pcntl para os Jobs.
RUN install-php-extensions \
    pdo_pgsql \
    pcntl \
    opcache \
    zip \
    intl

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Cache das dependências do Composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copia o restante do projeto
COPY . .

# Permissões necessárias para o Laravel
RUN chown -R www-data:www-data storage bootstrap/cache

# Configurações de ambiente para o FrankenPHP
ENV PORT=8080
EXPOSE 8080

# Comando para iniciar o servidor do FrankenPHP
# Ele já gerencia o servidor web e o PHP em um único processo
CMD ["frankenphp", "php-server", "--port", "8080"]
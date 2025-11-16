# Usar a imagem oficial do PHP com Apache
FROM php:8.2-apache

# Instalar extensões PHP necessárias (ex: mysqli, pdo_mysql)
RUN docker-php-ext-install pdo pdo_mysql

# Habilitar o módulo rewrite do Apache
RUN a2enmod rewrite

# Copiar o código da aplicação para o diretório do Apache
COPY . /var/www/html/

# Definir o diretório de trabalho
WORKDIR /var/www/html

# Instalar o Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instalar dependências do Composer
RUN composer install --no-dev --optimize-autoloader

# Expor a porta 80 (padrão do Apache)
EXPOSE 80

# O comando padrão do Apache (httpd-foreground) já está definido na imagem base
# CMD ["apache2-foreground"]

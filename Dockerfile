FROM php:8.2-cli

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensiones de PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Establecer directorio de trabajo
WORKDIR /app

# Copiar archivos
COPY . .

# Instalar dependencias sin ejecutar scripts
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Ejecutar autoload
RUN composer dump-autoload --optimize

# Dar permisos
RUN chmod -R 775 storage bootstrap/cache

# Exponer puerto
EXPOSE 8080

# Script de inicio que configura Laravel antes de arrancar
CMD cp -n .env.example .env 2>/dev/null || true && \
    php artisan key:generate --force && \
    php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate --force && \
    php artisan serve --host=0.0.0.0 --port=8080

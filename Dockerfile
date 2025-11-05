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

# Instalar dependencias SIN ejecutar scripts
RUN composer install --no-dev --no-scripts

# Dar permisos
RUN chmod -R 775 storage bootstrap/cache

# Exponer puerto
EXPOSE 8080

# Script de inicio con logs detallados
CMD set -e && \
    echo "[Railway] Iniciando configuración de Laravel..." && \
    echo "[Railway] Verificando archivos..." && \
    ls -la && \
    echo "[Railway] Creando .env desde .env.example..." && \
    cp .env.example .env && \
    echo "[Railway] Generando APP_KEY..." && \
    php artisan key:generate --force && \
    echo "[Railway] Cacheando configuración..." && \
    php artisan config:cache && \
    echo "[Railway] Cacheando rutas..." && \
    php artisan route:cache && \
    echo "[Railway] Ejecutando migraciones..." && \
    php artisan migrate --force && \
    echo "[Railway] Iniciando servidor en puerto 8080..." && \
    php artisan serve --host=0.0.0.0 --port=8080

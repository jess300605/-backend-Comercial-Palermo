FROM php:8.2-cli

# Instalar dependencias del sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Limpiar cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensiones de PHP
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Establecer directorio de trabajo
WORKDIR /app

# Copiar archivos
COPY . .

# Crear .env desde .env.example si no existe
RUN cp .env.example .env || true

# Instalar dependencias sin ejecutar scripts
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Generar APP_KEY
RUN php artisan key:generate

# Ahora ejecutar los scripts de composer
RUN composer dump-autoload --optimize

# Dar permisos
RUN chmod -R 775 storage bootstrap/cache

# Exponer puerto
EXPOSE 8080

# Comando de inicio
CMD php artisan config:cache && \
    php artisan route:cache && \
    php artisan migrate --force && \
    php artisan serve --host=0.0.0.0 --port=8080

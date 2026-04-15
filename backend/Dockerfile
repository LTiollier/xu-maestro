FROM php:8.4-cli-bookworm

# System deps
RUN apt-get update && apt-get install -y \
    git curl unzip libzip-dev libsqlite3-dev \
    && docker-php-ext-install zip pdo pdo_sqlite pcntl \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node.js 22 (for AI CLI tools)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# AI CLI tools (Linux binaries – host auth configs mounted via $HOME volume)
RUN npm install -g @google/gemini-cli @anthropic-ai/claude-code

# RTK-AI (token-optimized CLI proxy – needed because Claude Code hooks rewrite commands via rtk)
RUN curl -fsSL https://raw.githubusercontent.com/rtk-ai/rtk/refs/heads/master/install.sh | sh
ENV PATH="/root/.local/bin:${PATH}"

WORKDIR /app

# Install PHP deps
COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader --no-dev

# Copy source and finalize autoloader
COPY . .
RUN composer dump-autoload --optimize

# Build Laravel frontend assets (Vite/Tailwind)
RUN npm install && npm run build

COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/docker-entrypoint.sh"]

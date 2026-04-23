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

# RTK-AI (token-optimized CLI proxy)
RUN curl -fsSL https://raw.githubusercontent.com/rtk-ai/rtk/refs/heads/master/install.sh | sh \
    && mv /root/.local/bin/rtk /usr/local/bin/rtk

# Create a non-root user
ARG USER_ID=1000
ARG GROUP_ID=1000

RUN groupadd -g ${GROUP_ID} maestro || true && \
    useradd -l -u ${USER_ID} -g ${GROUP_ID} -m maestro || true

# Prepare directories for volumes mounted in docker-compose
RUN mkdir -p /workflows /runs /prompts /app/database /app/storage /app/bootstrap/cache && \
    chown -R maestro:maestro /workflows /runs /prompts /app

WORKDIR /app

# Install PHP deps
COPY --chown=maestro:maestro composer.json composer.lock* ./
USER maestro
RUN composer install --no-scripts --no-autoloader --no-dev

# Copy source and finalize autoloader
USER root
COPY . .
RUN chown -R maestro:maestro /app

USER maestro
RUN composer dump-autoload --optimize

# Build Laravel frontend assets (Vite/Tailwind)
USER root
RUN npm install && npm run build && chown -R maestro:maestro /app/node_modules /app/public

USER maestro

COPY --chown=maestro:maestro docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["/docker-entrypoint.sh"]

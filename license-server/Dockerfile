FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
    tini \
    awscli \
    jq \
    libpq-dev \
    curl \
    unzip \
    git \
 && docker-php-ext-install pdo_pgsql \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app
RUN chmod +x license-server/entrypoint.sh
RUN chmod +x license-server/prune_cli.php || true
EXPOSE 8001
ENTRYPOINT ["/usr/bin/tini", "--", "./license-server/entrypoint.sh"]

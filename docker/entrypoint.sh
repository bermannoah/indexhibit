#!/bin/sh
set -e

cd /var/www/html

# The repo ships the Apache rewrite rules as `htaccess`; Apache reads `.htaccess`.
if [ -f /var/www/html/htaccess ] && [ ! -f /var/www/html/.htaccess ]; then
    cp /var/www/html/htaccess /var/www/html/.htaccess
fi

# Generate config.php from environment variables when it doesn't exist yet
# (or when FORCE_CONFIG_GEN is set).
if [ ! -f /var/www/html/ndxzsite/config/config.php ] || [ "$FORCE_CONFIG_GEN" = "1" ]; then
    php /var/www/html/docker/generate-config.php
fi

# Ensure writable runtime directories exist.
mkdir -p \
    /var/www/html/files/gimgs \
    /var/www/html/files/dimgs \
    /var/www/html/ndxzsite/cache

chown -R www-data:www-data \
    /var/www/html/files \
    /var/www/html/ndxzsite/config \
    /var/www/html/ndxzsite/cache

# Non-interactive first-run install. The database may still be starting,
# so retry for a while before giving up.
if [ "$AUTO_INSTALL" = "1" ]; then
    i=0
    until (cd /var/www/html && php ndxzstudio/install-cli.php) >/dev/null 2>&1; do
        i=$((i+1))
        if [ "$i" -ge 30 ]; then
            echo "Indexhibit auto-install failed after 30 attempts." >&2
            break
        fi
        echo "Waiting for database before install ($i/30)..."
        sleep 2
    done
fi

exec "$@"

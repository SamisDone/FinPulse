#!/bin/bash
set -e

# Render sets PORT (default 10000); Apache must listen on it.
# Locally, fall back to port 80.
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80/Listen $PORT/" /etc/apache2/ports.conf
    sed -i "s/:80/:$PORT/" /etc/apache2/sites-available/000-default.conf
fi

# Start the cron daemon in the background
service cron start 2>/dev/null || true

# Hand off to the CMD (apache2-foreground)
exec "$@"

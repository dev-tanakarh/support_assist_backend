#!/usr/bin/env bash
set -eu

# Render injects PORT at runtime. Default keeps local Docker runs convenient.
PORT="${PORT:-10000}"
if ! printf '%s' "$PORT" | grep -Eq '^[0-9]+$'; then
  echo "PORT must be numeric" >&2
  exit 1
fi

sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:.*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground

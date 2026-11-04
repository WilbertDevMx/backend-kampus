#!/bin/sh
# Elige el certificado que usa Nginx: el de Let's Encrypt si certbot ya lo sacó, o uno autofirmado
# temporal para que Nginx pueda arrancar la primera vez (y en local con API_DOMAIN=localhost).
set -e

ssl=/etc/nginx/ssl
live="/etc/letsencrypt/live/${API_DOMAIN:?Falta API_DOMAIN en .env.production}"
mkdir -p "$ssl"

if [ -f "$live/fullchain.pem" ]; then
    ln -sf "$live/fullchain.pem" "$ssl/fullchain.pem"
    ln -sf "$live/privkey.pem" "$ssl/privkey.pem"
elif [ ! -e "$ssl/fullchain.pem" ]; then
    echo "kampus-certs: todavía no hay certificado de Let's Encrypt para $API_DOMAIN; uso uno autofirmado temporal."
    openssl req -x509 -nodes -newkey rsa:2048 -days 30 -subj "/CN=$API_DOMAIN" \
        -keyout "$ssl/privkey.pem" -out "$ssl/fullchain.pem" 2>/dev/null
fi

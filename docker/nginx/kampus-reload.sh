#!/bin/sh
# Toma el certificado más reciente y recarga Nginx sin cortar conexiones. Se corre solo cada 6 horas;
# después de sacar el primer certificado se corre a mano: docker compose ... exec nginx kampus-reload
set -e

kampus-certs
nginx -t -q
nginx -s reload
echo "kampus-reload: Nginx recargado."

#!/bin/sh
# Lo corre el entrypoint de la imagen de Nginx antes de arrancar (carpeta /docker-entrypoint.d).
set -e

kampus-certs

# Cada 6 horas vuelve a enlazar el certificado y recarga Nginx para tomar el que renovó certbot.
(
    while :; do
        sleep 21600
        kampus-reload || true
    done
) &

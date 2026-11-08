# Nginx del entorno de pruebas (T-24): configuración de Kampus y scripts del certificado.
FROM nginx:1.27-alpine

RUN apk add --no-cache openssl

COPY default.conf /etc/nginx/conf.d/default.conf
COPY kampus-certs.sh /usr/local/bin/kampus-certs
COPY kampus-reload.sh /usr/local/bin/kampus-reload
COPY 40-kampus.sh /docker-entrypoint.d/40-kampus.sh
RUN chmod +x /usr/local/bin/kampus-certs /usr/local/bin/kampus-reload /docker-entrypoint.d/40-kampus.sh

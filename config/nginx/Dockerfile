FROM nginx:1.27-alpine

# Run workers as www-data (uid/gid 82) — the same identity PHP-FPM uses in the
# php:*-alpine images. This lets the WordPress purge plugin delete FastCGI cache
# files that nginx created, without any extra nginx module.
RUN set -eux; \
    addgroup -g 82 -S www-data 2>/dev/null || true; \
    adduser -u 82 -D -S -G www-data www-data 2>/dev/null || true; \
    apk add --no-cache openssl curl; \
    mkdir -p /var/cache/nginx/wp /var/log/nginx; \
    chown -R www-data:www-data /var/cache/nginx /var/log/nginx

# Generates a self-signed cert on first boot if no Cloudflare Origin CA cert
# has been provided yet, so the stack always starts.
COPY docker-entrypoint.d/10-ensure-cert.sh /docker-entrypoint.d/10-ensure-cert.sh
RUN chmod +x /docker-entrypoint.d/10-ensure-cert.sh

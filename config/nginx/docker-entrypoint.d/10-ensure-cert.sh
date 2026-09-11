#!/bin/sh
# Ensure /etc/nginx/certs/origin.pem + origin.key exist.
# In production these are the Cloudflare Origin CA certificate and key
# (see config/nginx/certs/README.md). If missing, create a self-signed pair so
# nginx can start; Cloudflare "Full" (non-strict) mode will accept it, but
# switch to the Origin CA cert + "Full (strict)" before go-live.
set -e
CERT_DIR=/etc/nginx/certs
if [ ! -s "$CERT_DIR/origin.pem" ] || [ ! -s "$CERT_DIR/origin.key" ]; then
  echo "[ensure-cert] no origin cert found, generating self-signed for ${SITE_DOMAIN:-localhost}"
  openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout "$CERT_DIR/origin.key" -out "$CERT_DIR/origin.pem" \
    -subj "/CN=${SITE_DOMAIN:-localhost}" \
    -addext "subjectAltName=DNS:${SITE_DOMAIN:-localhost},DNS:localhost" >/dev/null 2>&1
  chmod 600 "$CERT_DIR/origin.key"
fi

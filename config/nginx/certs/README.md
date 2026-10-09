# Origin TLS certificate

Place two files here (both git-ignored):

- `origin.pem` — certificate
- `origin.key` — private key

`scripts/setup-vps.sh` copies them to `/etc/nginx/certs/` (or generates a
self-signed pair if they are missing).

## Production: Cloudflare Origin CA certificate (recommended)

Cloudflare dashboard → your zone → **SSL/TLS → Origin Server → Create Certificate**
→ RSA 2048, hostnames `example.com, *.example.com`, validity 15 years.
Copy the certificate into `origin.pem` and the private key into `origin.key`, then:

```
sudo bash scripts/setup-vps.sh
# or just:  sudo cp config/nginx/certs/origin.{pem,key} /etc/nginx/certs/ && sudo nginx -s reload
```

Set **SSL/TLS → Overview → Full (strict)** in Cloudflare.

## Fallback

If the files are missing, the setup script generates a self-signed pair so nginx
starts. Cloudflare must then be in **Full** (not strict) mode. Replace with the
Origin CA cert before go-live.

## Aggregator trust anchor

The aggregator is a different VPS. Its certificate is self-signed for
`aggregator.internal`. Copy that file to `/etc/nginx/ssl/site-aggregator.crt`
on this server. Nginx uses it only as `proxy_ssl_trusted_certificate` for the
search upstream, and PHP uses it as `--cacert` for private aggregator calls.

Do not install that file as this site's public certificate, and do not replace
`origin.pem`. Do not commit the certificate. `scripts/setup-vps.sh` leaves the
search proxy out until this file and `AGGREGATOR_IP` in
`/etc/news-wp/aggregator.env` are both present.

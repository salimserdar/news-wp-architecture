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

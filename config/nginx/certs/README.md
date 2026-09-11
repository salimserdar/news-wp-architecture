# Origin TLS certificate

Place two files here (both git-ignored):

- `origin.pem` — certificate
- `origin.key` — private key

## Production: Cloudflare Origin CA certificate (recommended)

Cloudflare dashboard → your zone → **SSL/TLS → Origin Server → Create Certificate**
→ RSA 2048, hostnames `example.com, *.example.com`, validity 15 years.
Copy the certificate into `origin.pem` and the private key into `origin.key`, then:

```
docker compose restart nginx
```

Set **SSL/TLS → Overview → Full (strict)** in Cloudflare.

## Fallback

If the files are missing, the nginx container generates a self-signed pair on start
so the stack boots. Cloudflare must then be in **Full** (not strict) mode. Replace with
the Origin CA cert before go-live.

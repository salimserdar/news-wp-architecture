# 08 — PHP-FPM havuz ayrımı ve kuyruk düzeltmesi (Ekim 2026)

Bu belge `refactor/pool` dalındaki değişikliklerin **ne**, **neden** ve **nasıl deploy
edilir** kaydıdır. Sorun: 8 vCPU / 32 GB kutuda, saniyede ~2 istek gelirken bile
site günün yarısı tıkalıydı ve editörler haber giremiyordu.

## 1. Teşhis — loglar ne söyledi

`nginx_logs/` içindeki 26 saatlik üretim logu (12–13 Ekim) incelendi.

| Gözlem | Değer |
|--------|-------|
| Origin'e gelen istek | ~2 req/s; PHP'ye düşen (MISS/BYPASS) ~1 req/s |
| Sakin dönemde render süresi | single p50 **0.15 s**, tag p50 **0.05 s**, home **0.23 s** |
| Tıkanıklık episodu | **63 adet**, toplam ~**12 saat**; bazıları 1 saati aşıyor |
| Tıkanıklıkta `urt` | 120–300 s'de yığılıyor, **200 OK dönüyor** |
| `/wp-admin/` (BYPASS) bekleme | 227 s; `async-upload.php` 60 s; `admin-ajax.php` 30 s |
| Origin HIT oranı | **%10** (HIT 21k / MISS 83k / STALE 45k) |
| En çok MISS üreten UA'lar | ExaSearchBot 8k, Applebot 5k, bingbot 5k, Reflectionbot 4k, AionBot 1.6k |
| Tag arşivi MISS | 34k (tek başına) |

Yorum: istekler PHP'de yavaş çalışmıyor, **boş bir child beklerken kuyrukta duruyor**.
300 s = `fastcgi_read_timeout`. Editörler bot isteklerinin arkasında aynı FIFO'da sıraya
giriyordu.

## 2. Kök nedenler

Dört şey birlikte çalışıyordu; hiçbiri tek başına değil.

### 2.1 nginx ↔ PHP-FPM keepalive (en kritik)

`upstream php_fpm { keepalive 16; }` + `fastcgi_keep_conn on` vardı. PHP-FPM child'ı
multiplex yapmaz: keepalive bağlantıyı aldığı nginx worker'ına **sabitlenir** ve o
bağlantıda bir sonraki isteği bekler. Başka bir worker'ın isteği backlog'a düşer ve
child boşta olsa bile servis alamaz; nginx boş bağlantıyı ancak 60 s sonra kapatır
(upstream `keepalive_timeout` varsayılanı).

8 worker × 16 slot = 128 boş bağlantı > 20 child. Yani düşük yükte bile child'lar
boş bağlantılara park ediyor, kuyruk büyüyordu.

Konteynerde doğrulandı: 2 child, 10 eşzamanlı anlık istek → ikisi **tam 60.05 s**
bekledi. Keepalive kapatılınca hepsi milisaniyede döndü.

### 2.2 Tek havuz + 4096 backlog + 300 s timeout

Editör ve bot aynı 20 child'ı ve aynı 4096 derinlikli kuyruğu paylaşıyordu. Kuyruk
dolmadığı için nginx hiçbir zaman "hata" görmüyor, `fastcgi_cache_use_stale error`
devreye girmiyor, herkes 5 dakikaya kadar bekliyordu.

### 2.3 Render içinde 8 s'lik dış çağrılar

`parts/header.html` içindeki ticker bloğu her sayfada çalışıyor. Transient (5 dk)
düşünce aggregator'a `timeout 8` ile gidiyor; aggregator yavaşsa her MISS 8 s child
tutuyor, başarısızlık **cache'lenmiyor**. 1 MISS/s × 8 s = 8 child; popular + videos
bloklarıyla havuz doluyor. Aynı desen `popular/posts.php` ve `videos/videos.php`'de.

### 2.4 Legal botlar ve 10 dakikalık arşiv TTL'i

ExaSearchBot, Reflectionbot, AionBot `$bad_bot` listesinde yoktu. Tag/kategori
arşivlerine 10 dk TTL vardı; okuyucu nadiren, bot sürekli girdiği için neredeyse her
bot isteği render oluyordu.

## 3. Yapılan değişiklikler

### PHP-FPM — iki havuz

| Dosya | Değişiklik |
|-------|-----------|
| `config/php/pool-www.conf` | Anonim MISS'ler. `pm.max_children` 20 → **48**, start/min/max spare 16/8/24, `listen.backlog` 4096 → **256**, terminate 60 s |
| `config/php/pool-editors.conf` (yeni) | Giriş yapmış kullanıcılar, `/wp-admin/`, `/wp-login.php`. 16 child, backlog 512, terminate **300 s**, socket `/run/php/php8.3-fpm-editors.sock`, `pm.status_path = /-/fpm-status-editors`, ayrı slowlog |
| `/etc/php/8.3/fpm/php-fpm.conf` (setup-vps.sh yazar) | `process_control_timeout = 10s` — reload'ın in-flight istekleri bitirmesini sağlar; Ubuntu varsayılanı 0 |

Neden 48: render 50–300 ms, child'lar CPU'ya değil I/O'ya takılıyor; 48 × ~100 MB RSS
≈ 5 GB, rahat sığar. Neden backlog 256: kuyruk dolunca nginx `connect()` anında hata
alır → `STALE` servis eder; kimse dakikalarca beklemez.

### nginx

| Dosya | Değişiklik |
|-------|-----------|
| `config/nginx/http.conf` | `php_fpm_editors` upstream'i; `map $http_cookie $php_upstream` (`wordpress_logged_in_` → editör havuzu); **`keepalive` kaldırıldı**; `$bad_bot`'a `ExaSearchBot|Reflectionbot|AionBot`; `$crawler_key` map + `limit_req_zone crawlers rate=4r/s` (Applebot, bingbot, Yandex, DDG, facebook — Googlebot bilinçli olarak dışarıda) |
| `config/nginx/site.conf` | `fastcgi_read/send_timeout 90s` server seviyesinde; `location ~ ^/wp-admin/.*\.php$` → editör havuzu, 300 s; `/wp-login.php` → editör havuzu; `location ~ \.php$` → `fastcgi_pass $php_upstream` + `limit_req zone=crawlers burst=20 nodelay`; `/-/fpm-status` ve `/-/fpm-status-editors` (sadece 127.0.0.1) |
| `config/nginx/snippets/fastcgi-php.conf` | `fastcgi_keep_conn on` ve timeout'lar kaldırıldı (timeout'lar artık site.conf'ta; aynı blokta ikinci tanım `nginx -t`'de duplicate hatası verir) |

### Tema ve mu-plugin

| Dosya | Değişiklik |
|-------|-----------|
| `wp/themes/tr724-news/functions.php` | `TR724_REMOTE_TIMEOUT = 2`, `TR724_REMOTE_BACKOFF = 60`; `tr724_remote_backing_off()`, `tr724_remote_mark_failed()` |
| `blocks/ticker/rates.php`, `blocks/popular/posts.php`, `blocks/videos/videos.php` | timeout 8 → 2 s; bir hatadan sonra 60 s boyunca HTTP çağrısı yapmadan `_last` transient'tan servis; videos için `_last` (12 h) eklendi |
| `wp/mu-plugins/cache-control.php` | `TTL_ARCHIVE = 6h` — tag/kategori/yazar/tarih arşivleri ve `/page/N/`. Publish zaten `urls_for_post()` ile ilgili arşivleri purge ediyor, tazelik kaybı yok |

### Operasyon

| Dosya | Değişiklik |
|-------|-----------|
| `scripts/setup-vps.sh` | `editors.conf` yazar; `process_control_timeout`; FPM/nginx `restart` → **`reload`**; MariaDB yalnızca cnf değiştiyse veya `DB_RESTART=1` ise restart, aksi hâlde `SET GLOBAL max_connections`; editör socket'i oluşana kadar bekler |
| `config/mariadb/zz-tuning.cnf` | `max_connections` 80 → **200** (48 + 16 child + cron + CLI) |
| `Makefile` | `make reload-php` (iki havuz dosyası, `php-fpm8.3 -t`, graceful reload) |
| `scripts/cache-stats.sh` | İki havuzun `listen queue` / `max children reached` değerleri |
| `scripts/loadtest-observe.sh` | `SOCK` / `STATUS_PATH` ile havuz seçimi |
| `.env.example` | `PHP_MAX_CHILDREN=48`, `PHP_EDITOR_CHILDREN=16` |
| `docs/*` | 01, 02, 05, reference/architecture, reference/resources güncellendi |

## 4. Yerelde nasıl doğrulandı

Docker ile (`nginx:1.24` = Ubuntu 24.04 sürümü, `php:8.3-fpm`):

- `nginx -t` ve `php-fpm -t` geçti.
- Yönlendirme: anonim → `www` + ikinci istekte `HIT`; login cookie → `editors` + `BYPASS`;
  `/wp-admin/`, `admin-ajax.php`, `wp-login.php` → `editors`.
- Reflectionbot → 403. Applebot 30 hızlı istek → 22 × 200 + 8 × 429. Normal UA sınırsız.
- `/-/fpm-status` dışarıdan 403, loopback'ten iki havuzun raporu.
- Dolu havuz simülasyonu (2 child, backlog 1): cache'li URL anında `STALE`;
  keepalive açıkken 60 s takılma, kapalıyken yok.

## 5. Deploy — adım adım (canlıda, kesintisiz)

Hepsi `reload`; hiçbir adımda `restart` yok. Sakin pencere: 07–09 UTC veya 21 UTC civarı.

### Ön koşul (bir kez)

```bash
ssh VPS
cd /opt/news-wp-architecture
git fetch && git checkout refactor/pool && git pull     # veya main'e merge edildiyse main

# Graceful reload için. Ubuntu'da 0 gelir; setup-vps.sh de yazar ama önce elle güvenceye al.
sudo sed -i -E 's/^;?\s*process_control_timeout\s*=.*/process_control_timeout = 10s/' /etc/php/8.3/fpm/php-fpm.conf
grep process_control_timeout /etc/php/8.3/fpm/php-fpm.conf || echo 'process_control_timeout = 10s' | sudo tee -a /etc/php/8.3/fpm/php-fpm.conf
sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm

# RAM payını doğrula: child başı RSS ve boş bellek
ps -ylC php-fpm8.3 --sort:rss | tail -5
free -m
```

Child başı RSS 150 MB'ın üstündeyse `PHP_MAX_CHILDREN` için `.env`'e 32 yaz.

### Adım 1 — MariaDB bağlantı sayısı (önce bu; child sayısı 80'i aşacak)

```bash
sudo mysql -e "SET GLOBAL max_connections = 200; SHOW VARIABLES LIKE 'max_connections';"
sudo install -m 0644 config/mariadb/zz-tuning.cnf /etc/mysql/mariadb.conf.d/zz-tuning.cnf   # kalıcılık; restart YOK
```

### Adım 2 — PHP-FPM havuzları

```bash
sudo make reload-php
# Çıktıda iki socket görünmeli:
#   /run/php/php8.3-fpm.sock
#   /run/php/php8.3-fpm-editors.sock
sudo journalctl -u php8.3-fpm --since "2 min ago" --no-pager | tail -5
```

nginx hâlâ eski config'le eski socket'e konuşuyor; kesinti yok.

### Adım 3 — nginx (FPM'den SONRA; editör socket'i var olmalı)

```bash
sudo make reload-nginx          # http.conf + site.conf kopyala, nginx -t, reload
# Snippet'ler repoya symlink; git pull ile zaten güncellendi, reload ile devreye girdi.

# Doğrula
curl -skI -H "Host: www.tr724.com" https://127.0.0.1/ | grep -iE '^HTTP|x-fastcgi-cache'
curl -s http://127.0.0.1/-/fpm-status | grep -E '^pool|listen queue:|active'
curl -s http://127.0.0.1/-/fpm-status-editors | grep -E '^pool|listen queue:|active'
```

`nginx -t` geçmezse reload olmaz, eski config çalışmaya devam eder.

### Adım 4 — Tema ve mu-plugin

```bash
sudo bash scripts/deploy-theme.sh        # rsync --delete + systemctl reload php8.3-fpm
sudo bash scripts/deploy-mu-plugins.sh
```

### Adım 5 — Arşiv cache'ini ısıt (isteğe bağlı)

Yeni 6 saatlik TTL ancak sayfa yeniden render edilince başlar. Beklersen kendi
kendine dolar; hızlandırmak için:

```bash
scripts/wp.sh news-cache warm https://www.tr724.com/ https://www.tr724.com/feed/
```

### Adım 6 — 30 dakika izle

```bash
make stats                      # iki havuzda listen queue 0 kalmalı, max children reached artmamalı
tail -f /var/log/php8.3-fpm-slow.log /var/log/php8.3-fpm-editors-slow.log
make log-pressure ARGS="--since 30m"
# Editöre yazdırma testi: wp-admin'de bir yazı kaydet, süreyi not et.
```

Başarı ölçütü: `make stats`'te `listen queue: 0`; access log'da `urt` > 10 s olan
istek sayısı sıfıra yakın; editör kaydetme < 2 s.

### Geri alma

Her adım bağımsız geri alınır:

```bash
# nginx
git checkout main -- config/nginx && sudo make reload-nginx
# PHP-FPM
git checkout main -- config/php && sudo rm /etc/php/8.3/fpm/pool.d/editors.conf && sudo make reload-php
# (editors.conf silinince $php_upstream editör socket'ine 502 verir; nginx'i de geri al)
# MariaDB
sudo mysql -e "SET GLOBAL max_connections = 80;"
```

`setup-vps.sh` ilk kurulumda orijinal havuz dosyasını `www.conf.dist` olarak saklar; o da fallback.

## 6. Deploy sonrası bakılacaklar (repoda değil)

- **Yoast eksik dosyaları.** `error.log`'da 2 saatte 15k kez
  `wordpress-seo/.../indexables-update-listener-integration.php: No such file`
  (ACF ClassLoader üzerinden). Günde ~340 MB log. Sunucuda Yoast'ı yeniden kur:
  `scripts/wp.sh plugin install wordpress-seo --force`.
- **Anonim `admin-ajax.php`** artık editör havuzuna gidiyor. Bir eklenti bunu frontend'de
  yoğun kullanıyorsa `/-/fpm-status-editors`'ta görünür; gerekirse `site.conf`'ta
  `admin-ajax.php` için ayrı bir location ile `www`'ye yönlendir.
- **Cloudflare tarafı.** Origin HIT oranı %10; Cloudflare'in HTML cache kuralları
  ([04](04-cloudflare-and-go-live.md)) kontrol edilmeli. Verified bot kategorisi için
  Cloudflare Rate Limiting origin'deki `limit_req`'i tamamlar.

## 7. Bir daha yapılmaması gerekenler

- PHP-FPM upstream'lerine `keepalive` veya `fastcgi_keep_conn on` eklemek.
- `listen.backlog`'u public havuzda tekrar 4096'ya çıkarmak.
- `setup-vps.sh`'de `systemctl restart` kullanmak.
- Render yolunda 2 s'den uzun timeout'lu dış HTTP çağrısı eklemek; ya cron'da çek ya da
  `tr724_remote_backing_off()` ile sar.

.PHONY: up down restart build logs ps wp purge warm stats backup post-import reload-nginx

up:            ## build + start everything
	docker compose up -d --build
down:          ## stop everything (data volumes are kept)
	docker compose down
restart:       ## restart PHP pools (e.g. after config change)
	docker compose restart php php-admin
build:
	docker compose build --pull
logs:          ## follow all logs
	docker compose logs -f --tail=100
ps:
	docker compose ps
wp:            ## make wp ARGS="plugin list"
	scripts/wp.sh $(ARGS)
purge:         ## purge nginx + Cloudflare cache for the whole site
	scripts/wp.sh news-cache purge-all
warm:
	scripts/wp.sh news-cache warm
stats:         ## cache hit ratios, top misses, container resources
	scripts/cache-stats.sh
backup:
	scripts/backup.sh
post-import:   ## after importing DB + wp-content
	scripts/post-import.sh
reload-nginx:  ## test + reload nginx config without dropping connections
	docker compose exec nginx nginx -t && docker compose exec nginx nginx -s reload

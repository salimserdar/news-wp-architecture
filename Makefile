.PHONY: up down restart build logs ps wp purge warm stats backup post-import reload-nginx gcs loadtest-urls loadtest-observe loadtest-observe-native

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
gcs:           ## make gcs ARGS="check|smoke|upload FILE|download PATH"
	scripts/gcs.sh $(ARGS)
post-import:   ## after importing DB + wp-content
	scripts/post-import.sh
reload-nginx:  ## test + reload nginx config without dropping connections
	docker compose exec nginx nginx -t && docker compose exec nginx nginx -s reload
loadtest-urls: ## permalinks → loadtest/urls.json  (ARGS="--create-draft")
	scripts/loadtest-urls.sh $(ARGS)
loadtest-observe: ## sample origin metrics to CSV while k6 runs (Docker VPS)
	scripts/loadtest-observe.sh
loadtest-observe-native: ## sample nginx/php/mysql on a native (no Docker) VPS
	scripts/loadtest-observe-native.sh

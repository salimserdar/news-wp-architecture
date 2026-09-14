.PHONY: purge warm stats backup post-import reload-nginx gcs pull-gcs loadtest-urls loadtest-observe wp

wp:            ## make wp ARGS="plugin list"
	scripts/wp.sh $(ARGS)
purge:         ## purge nginx + Cloudflare cache for the whole site
	scripts/wp.sh news-cache purge-all
warm:
	scripts/wp.sh news-cache warm
stats:         ## cache hit ratios, top misses, FPM / MariaDB
	scripts/cache-stats.sh
backup:
	scripts/backup.sh
gcs:           ## make gcs ARGS="check|smoke|grant-vm VM ZONE|upload FILE"
	scripts/gcs.sh $(ARGS)
pull-gcs:      ## download DB + wp-content from gs://tr724-backup into import/
	scripts/pull-gcs-backup.sh
post-import:   ## after importing DB + wp-content
	scripts/post-import.sh
reload-nginx:  ## test + reload nginx config without dropping connections
	nginx -t && nginx -s reload
loadtest-urls: ## permalinks → loadtest/urls.json  (ARGS="--create-draft")
	scripts/loadtest-urls.sh $(ARGS)
loadtest-observe: ## sample nginx/php/mysql to CSV while k6 runs
	scripts/loadtest-observe.sh

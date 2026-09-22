.PHONY: purge warm stats backup post-import reload-nginx gcs pull-gcs create-gce loadtest-urls loadtest-observe wp

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
gcs:           ## make gcs ARGS="check|smoke|grant-sa|grant-vm VM ZONE"
	scripts/gcs.sh $(ARGS)
create-gce:    ## laptop: grant bucket IAM then create the Ubuntu VM
	scripts/create-gce-vm.sh
pull-gcs:      ## download DB + wp-content from gs://tr724-backup into import/
	scripts/pull-gcs-backup.sh
post-import:   ## after importing DB + wp-content
	scripts/post-import.sh
reload-nginx:  ## install http/site conf, test, reload without dropping connections
	cp -a config/nginx/http.conf /etc/nginx/conf.d/00-news-wp.conf
	cp -a config/nginx/site.conf /etc/nginx/sites-available/wordpress
	nginx -t && nginx -s reload
loadtest-urls: ## permalinks → loadtest/urls.json  (ARGS="--create-draft")
	scripts/loadtest-urls.sh $(ARGS)
loadtest-observe: ## sample nginx/php/mysql to CSV while k6 runs
	scripts/loadtest-observe.sh

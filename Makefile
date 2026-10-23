.PHONY: purge warm stats backup r2-db-backup post-import reload-nginx reload-php gcs pull-gcs create-gce loadtest-urls loadtest-observe wp log-attack log-pressure log-goaccess

PHP_MAX_CHILDREN    ?= 48
PHP_EDITOR_CHILDREN ?= 16

wp:            ## make wp ARGS="plugin list"
	scripts/wp.sh $(ARGS)
purge:         ## purge nginx + Cloudflare cache for the whole site
	scripts/wp.sh news-cache purge-all
warm:
	scripts/wp.sh news-cache warm
stats:         ## cache hit ratios, top misses, FPM / MariaDB
	scripts/cache-stats.sh
log-attack:    ## ARGS="--since 15m" or "errors|bots|top [flags]"
	scripts/access-log.sh $(if $(ARGS),$(ARGS),attack)
log-pressure:  ## ARGS="--since 1d" — IPs and crawlers that hold the origin
	scripts/access-log.sh pressure $(ARGS)
log-goaccess:  ## ARGS="term|html|live" (html → logs/goaccess.html, live on 127.0.0.1)
	scripts/access-log.sh goaccess $(ARGS)
backup:
	scripts/backup.sh
r2-db-backup:  ## ARGS="provision|upload FILE|check|restore YYYYMMDD FILE"
	scripts/r2-db-backup.sh $(ARGS)
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
reload-php:    ## install both pool files, test, graceful reload (PHP_MAX_CHILDREN / PHP_EDITOR_CHILDREN)
	@case "$(PHP_MAX_CHILDREN)" in [1-9]|[1-9][0-9]*) ;; *) echo "PHP_MAX_CHILDREN must be a positive integer (got '$(PHP_MAX_CHILDREN)')"; exit 1 ;; esac
	@case "$(PHP_EDITOR_CHILDREN)" in [1-9]|[1-9][0-9]*) ;; *) echo "PHP_EDITOR_CHILDREN must be a positive integer (got '$(PHP_EDITOR_CHILDREN)')"; exit 1 ;; esac
	sed -E "s/^pm.max_children = .*/pm.max_children = $(PHP_MAX_CHILDREN)/" config/php/pool-www.conf > /etc/php/8.3/fpm/pool.d/www.conf
	sed -E "s/^pm.max_children = .*/pm.max_children = $(PHP_EDITOR_CHILDREN)/" config/php/pool-editors.conf > /etc/php/8.3/fpm/pool.d/editors.conf
	touch /var/log/php8.3-fpm-editors-slow.log && chown www-data:www-data /var/log/php8.3-fpm-editors-slow.log
	bash scripts/reload-php-fpm.sh
	@sleep 2; ls -l /run/php/php8.3-fpm.sock /run/php/php8.3-fpm-editors.sock
loadtest-urls: ## permalinks → loadtest/urls.json  (ARGS="--create-draft")
	scripts/loadtest-urls.sh $(ARGS)
loadtest-observe: ## sample nginx/php/mysql to CSV while k6 runs
	scripts/loadtest-observe.sh

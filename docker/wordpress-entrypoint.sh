#!/usr/bin/env bash
# First boot copies WordPress core into the Docker volume. Themes, plugins,
# and mu-plugins stay the bind-mounted repo directories, so bundled themes
# (Twenty Twenty-Three) and plugins (Akismet, Hello Dolly) are not written
# into git.
set -Eeuo pipefail

cd /var/www/html

if [ ! -e index.php ] && [ ! -e wp-includes/version.php ]; then
	echo >&2 "Copying WordPress core (leaving wp/themes, wp/plugins, and wp/mu-plugins in place)..."
	tar --create --file - --directory /usr/src/wordpress \
		--owner www-data --group www-data \
		--exclude './wp-content/themes' \
		--exclude './wp-content/plugins' \
		--exclude './wp-content/mu-plugins' \
		. | tar --extract --file -
	echo >&2 "Complete! WordPress core has been copied to $PWD"
fi

# First boot writes these via WORDPRESS_CONFIG_EXTRA. An existing volume
# already has wp-config.php, so insert any missing constants before WordPress loads.
insert_wp_config_define() {
	local name="$1"
	local line="$2"
	if [ ! -f wp-config.php ] || grep -qF "$name" wp-config.php; then
		return 0
	fi
	# The Docker wp-config evals WORDPRESS_CONFIG_EXTRA on every request.
	if [ -n "${WORDPRESS_CONFIG_EXTRA:-}" ] && grep -qF "$name" <<<"${WORDPRESS_CONFIG_EXTRA}"; then
		return 0
	fi
	if ! grep -q 'wp-settings.php' wp-config.php; then
		echo >&2 "wp-config.php has no wp-settings.php require; cannot add ${name}"
		exit 1
	fi
	echo >&2 "Adding ${name} to wp-config.php..."
	awk -v define_line="$line" '
		/wp-settings\.php/ && !inserted {
			print define_line
			inserted = 1
		}
		{ print }
	' wp-config.php > wp-config.php.new
	chown --reference=wp-config.php wp-config.php.new
	chmod --reference=wp-config.php wp-config.php.new
	mv wp-config.php.new wp-config.php
}

insert_wp_config_define SITE_AGGREGATOR_SERVICE_URL "define( 'SITE_AGGREGATOR_SERVICE_URL', getenv( 'SITE_AGGREGATOR_SERVICE_URL' ) ?: 'http://host.docker.internal:3000' );"
insert_wp_config_define SITE_AGGREGATOR_READ_API_KEY "define( 'SITE_AGGREGATOR_READ_API_KEY', getenv( 'SITE_AGGREGATOR_READ_API_KEY' ) ?: '' );"
insert_wp_config_define YOUTUBE_API_KEY "define( 'YOUTUBE_API_KEY', getenv( 'YOUTUBE_API_KEY' ) ?: '' );"
insert_wp_config_define YOUTUBE_CHANNEL_ID "define( 'YOUTUBE_CHANNEL_ID', getenv( 'YOUTUBE_CHANNEL_ID' ) ?: '' );"

exec docker-entrypoint.sh "$@"

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

exec docker-entrypoint.sh "$@"

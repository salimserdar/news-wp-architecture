# WordPress + PHP-FPM 8.3 with the extensions a fast news site needs:
#   igbinary + redis  -> Redis Object Cache plugin (compact serialisation)
#   imagick           -> better thumbnails, WebP / AVIF generation
#   opcache           -> already in the base image, tuned in conf.d/zz-wp.ini
#
# Based on the official image so WordPress core is installed into the
# ./wordpress bind mount on first start and wp-config.php is env-driven.
FROM wordpress:php8.3-fpm-alpine

# mlocati/docker-php-extension-installer: handles build deps and cleanup, and
# compiles phpredis with igbinary support when igbinary is installed first.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions igbinary redis imagick

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD php -r 'exit((int) !@fsockopen("127.0.0.1", 9000));'

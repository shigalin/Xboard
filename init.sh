#!/bin/bash

rm -rf composer.phar
wget https://github.com/composer/composer/releases/latest/download/composer.phar -O composer.phar
php composer.phar install -vvv
git submodule update --init --recursive --force
php artisan xboard:install

if [ -f scripts/customize-admin.php ]; then
  php scripts/customize-admin.php \
    || echo "Warning: admin customization skipped; serving the upstream admin bundle." >&2
fi

if [ -f "/etc/init.d/bt" ] || [ -f "/.dockerenv" ]; then
  chown -R www:www $(pwd);
fi

if [ -d ".docker/.data" ]; then
  chmod -R 777 .docker/.data
fi

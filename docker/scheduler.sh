#!/bin/sh
set -e

cd /var/www/html

while true
do
    php artisan schedule:run --verbose --no-interaction || true
    sleep 60
done

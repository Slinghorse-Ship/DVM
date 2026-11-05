#!/bin/sh
set -eu
umask 0077
php /opt/dvm/initialize.php initialize
php /opt/dvm/apache-config.php
# Only the volume's owner is adjusted on restart; existing private file modes remain intact.
if [ "$(stat -c %u /var/lib/dvm)" != "$(id -u www-data)" ]; then
    chown -R www-data:www-data /var/lib/dvm
fi
exec docker-php-entrypoint "$@"

#!/bin/sh
sleep 180
php /var/www/html/wp-content/plugins/conexao-event-importer/tests/c2-live-import-one.php heritage_week_dublin > /var/www/html/wp-content/plugins/conexao-event-importer/tests/c2-hw-dublin-rerun.log 2>&1

#!/usr/bin/env bash
set -euo pipefail

: "${MYSQL_DATABASE:?}"
: "${MYSQL_USER:?}"
: "${MYSQL_ROOT_PASSWORD:?}"

export MYSQL_PWD="${MYSQL_ROOT_PASSWORD}"

echo "Esperando a MariaDB..."
for _ in $(seq 1 60); do
  if mariadb-admin ping -h db -uroot --silent; then
    break
  fi
  sleep 2
done
mariadb-admin ping -h db -uroot --silent

marker="$(mariadb -h db -uroot --batch --skip-column-names -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${MYSQL_DATABASE}' AND table_name='wp_beaconlab_import_ok'")"
if [[ "${marker}" == "1" ]]; then
  echo "La base ya fue importada. Se omite."
  exit 0
fi

echo "Recreando la base ${MYSQL_DATABASE}..."
mariadb -h db -uroot --batch <<SQL
DROP DATABASE IF EXISTS \`${MYSQL_DATABASE}\`;
CREATE DATABASE \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL

echo "Importando database.sql..."
{
  echo "SET NAMES utf8mb4;"
  echo "SET FOREIGN_KEY_CHECKS=0;"
  echo "SET UNIQUE_CHECKS=0;"
  echo "SET sql_mode='NO_ENGINE_SUBSTITUTION';"
  # SERVMASK_PREFIX_ es el prefijo de All-in-One WP Migration.
  # 0x, es un binario vacío que MariaDB 11 no acepta.
  sed -e 's/`SERVMASK_PREFIX_/`wp_/g' -e "s/,0x,/,X'',/g" /dump/database.sql
  echo "CREATE TABLE wp_beaconlab_import_ok (id INT PRIMARY KEY);"
  echo "INSERT INTO wp_beaconlab_import_ok (id) VALUES (1);"
  echo "SET FOREIGN_KEY_CHECKS=1;"
} | mariadb -h db -uroot --default-character-set=utf8mb4 --max_allowed_packet=256M "${MYSQL_DATABASE}"

echo "Importación terminada."

#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html
SITE_URL="${BEACONLAB_URL:-http://beaconlab.localhost}"
OLD_URLS=(
  "https://beaconlab.us"
  "http://beaconlab.us"
  "https://www.beaconlab.us"
  "http://www.beaconlab.us"
)
OLD_PATH="/home/u541804962/domains/beaconlab.us/public_html"
NEW_PATH="/var/www/html"

echo "Esperando a que WordPress esté instalado..."
for _ in $(seq 1 60); do
  if wp core is-installed --allow-root --path=/var/www/html >/dev/null 2>&1; then
    break
  fi
  sleep 3
done
wp core is-installed --allow-root --path=/var/www/html

home="$(wp db query "SELECT option_value FROM wp_options WHERE option_name='home'" --skip-column-names --allow-root | tr -d '[:space:]')"
if [[ "${home}" == "${SITE_URL}" ]]; then
  echo "El sitio ya apunta a ${SITE_URL}."
else
  echo "Reemplazando prefijo interno y URLs hacia ${SITE_URL}..."
  wp search-replace 'SERVMASK_PREFIX_' 'wp_' --all-tables --skip-columns=guid --allow-root
  for old in "${OLD_URLS[@]}"; do
    wp search-replace "${old}" "${SITE_URL}" --all-tables --skip-columns=guid --allow-root
    escaped_old="${old//\//\\/}"
    escaped_new="${SITE_URL//\//\\/}"
    wp search-replace "${escaped_old}" "${escaped_new}" --all-tables --skip-columns=guid --allow-root
  done
  wp search-replace '//beaconlab.us' '//beaconlab.localhost' --all-tables --skip-columns=guid --allow-root
  wp search-replace '\/\/beaconlab.us' '\/\/beaconlab.localhost' --all-tables --skip-columns=guid --allow-root
  wp search-replace "${OLD_PATH}" "${NEW_PATH}" --all-tables --allow-root
  escaped_old_path="${OLD_PATH//\//\\/}"
  escaped_new_path="${NEW_PATH//\//\\/}"
  wp search-replace "${escaped_old_path}" "${escaped_new_path}" --all-tables --allow-root
  wp db query "UPDATE wp_blogs SET domain='beaconlab.localhost' WHERE domain=''" --allow-root || true
  wp db query "UPDATE wp_site SET domain='beaconlab.localhost' WHERE domain=''" --allow-root || true
fi

if [[ -f /package.json ]]; then
  mapfile -t plugins < <(php -r '$p=json_decode(file_get_contents("/package.json"), true); foreach (($p["Plugins"] ?? []) as $plugin) { echo $plugin, PHP_EOL; }')
else
  plugins=()
fi
plugins+=("wp-ai-translator/wp-ai-translator.php")

# Hostinger asume su plataforma y agota la memoria de PHP fuera de ella.
wp plugin deactivate hostinger-ai-assistant hostinger-easy-onboarding hostinger --allow-root || true

ordered=()
for plugin in "${plugins[@]}"; do
  case "${plugin}" in
    hostinger|hostinger/*|hostinger-*/*) continue ;;
  esac
  ordered+=("${plugin}")
done
# Elementor tiene que estar activo antes que Elementor Pro.
priority=(elementor/elementor.php sitepress-multilingual-cms/sitepress.php)
plugins=()
for plugin in "${priority[@]}"; do
  for candidate in "${ordered[@]}"; do
    if [[ "${candidate}" == "${plugin}" ]]; then
      plugins+=("${plugin}")
    fi
  done
done
for plugin in "${ordered[@]}"; do
  skip=0
  for preferred in "${priority[@]}"; do
    if [[ "${plugin}" == "${preferred}" ]]; then
      skip=1
    fi
  done
  if [[ "${skip}" == "0" ]]; then
    plugins+=("${plugin}")
  fi
done

for plugin in "${plugins[@]}"; do
  if wp plugin is-installed "${plugin}" --allow-root; then
    wp plugin activate "${plugin}" --allow-root || echo "No se pudo activar ${plugin}"
  else
    echo "Plugin ausente: ${plugin}"
  fi
done

wp rewrite flush --allow-root || true
wp cache flush --allow-root || true

if [[ -f /package.json ]]; then
  theme="$(php -r '$p=json_decode(file_get_contents("/package.json"), true); echo $p["Stylesheet"] ?? $p["Template"] ?? "";')"
  if [[ -n "${theme}" ]]; then
    wp theme activate "${theme}" --allow-root || echo "No se pudo activar el tema ${theme}"
  fi
fi

echo "Sitio listo: $(wp option get home --allow-root) (DB home=$(wp db query "SELECT option_value FROM wp_options WHERE option_name='home'" --skip-column-names --allow-root | tr -d '[:space:]'))"

# Changelog

## 1.8.0

### Added

- Traducción de campos SEO de **All in One SEO (AIOSEO)** tras completar un job WPML: `title`, `description`, `og_*`, `twitter_*`.
- Opción de ajustes **Traducir campos SEO de AIOSEO** (activada por defecto).
- Meta `_wpai_seo_source_hash` para no pisar ediciones manuales en el idioma destino.
- WP-CLI: `wp wpai seo-backfill [--post_type=publicacion] [--ids=…] [--dry-run]` para corregir posts ya traducidos.
- Copia sin traducir de imágenes sociales AIOSEO (`og_image_*`, `twitter_image_*`, `twitter_use_og`).

### Notes

- Si la traducción SEO falla, se registra en el log del plugin y el job de contenido sigue OK.
- El backfill respeta rate limit del proveedor (MiniMax/Ollama).

## 1.7.2

- Release de mantenimiento (auto patch).

## 1.7.0 / 1.7.1

- Motor WPML + cola + MiniMax/Ollama (ver releases en GitHub).

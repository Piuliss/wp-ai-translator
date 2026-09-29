# Traducción AI (WPML)

Plugin de WordPress que traduce contenidos de **WPML** usando proveedores de IA locales o en la nube (**Ollama** y **MiniMax**), sin depender de créditos ATE de WPML.

Proyecto personal de código abierto. **No está afiliado** a WPML, Ollama ni MiniMax.

## Características

- Proveedores: **Ollama** (self-hosted) y **MiniMax** (API compatible con OpenAI)
- Configuración 100% desde el admin de WordPress (sin `.env` en el servidor)
- Claves API cifradas en la base de datos (OpenSSL + salts de WordPress)
- Cola asíncrona con WP-Cron y reintento ante rate limits / ventanas de tokens
- Interfaz en español: menú **Traducción AI**
- Hardening básico: validación de URLs, preview público desactivado por defecto, sanitizado de salida del modelo

## Requisitos

- WordPress 6.x (probado con PHP 8.2)
- [WPML](https://wpml.org/) + Translation Management
- OpenSSL en PHP (para cifrar secretos)
- Para Ollama: un endpoint HTTP alcanzable desde WordPress
- Para MiniMax: API key válida (`api.minimax.io` / `api.minimaxi.com`)

## Instalación

1. Descargá el ZIP del [último release](https://github.com/Piuliss/wp-ai-translator/releases/latest) (`wp-ai-translator.zip`) o cloná este repositorio.
2. En WordPress: **Plugins → Añadir nuevo → Subir plugin**.
3. Activá **Traducción AI (WPML)**.
4. Andá a **Traducción AI** en el menú de admin.
5. Elegí el proveedor, guardá credenciales y activá **Motor WPML**.

Estructura del plugin en el repo:

```text
wp-content/plugins/wp-ai-translator/
```

## Actualizaciones

El plugin comprueba releases públicos de GitHub y ofrece actualizar desde **Plugins** en WordPress (sin wordpress.org).

## Releases

Al subir a `main` un cambio en el plugin con una **versión nueva** (header `Version` + `WPAI_VERSION`), GitHub Actions crea el tag `vX.Y.Z` y publica un release con:

- `wp-ai-translator.zip` (para instalar / actualizar)
- `wp-ai-translator-X.Y.Z.zip` (archivo versionado)

Si el tag ya existe, el workflow no vuelve a publicar.
## Uso rápido

1. Configurá Ollama o MiniMax y comprobá con **Probar proveedor**.
2. Activá **Usar este proveedor como motor de WPML**.
3. En WPML, enviá trabajos de traducción (manual o Translate Everything).
4. Los jobs locales se encolan y se procesan en segundo plano.

Si venís de la versión antigua `wp-ollama-translator`, al activar `wp-ai-translator` se migran opciones y cola automáticamente.

## Seguridad

- No expongas endpoints internos sin protección.
- Quien tenga acceso a la BD y a `wp-config.php` puede descifrar las claves almacenadas (limitación habitual en WordPress).
- El preview `?wpai_lang=` está **desactivado** por defecto y solo funciona para administradores si lo activás a mano.
- Revisá siempre el contenido traducido antes de publicarlo.

## Aviso legal / exención de responsabilidad

Este software se ofrece **tal cual**, sin garantías de ningún tipo.

El autor **no se hace responsable** del uso que se haga de este plugin, ni de daños, pérdida de datos, costes de API, interrupciones del servicio, ni de posibles conflictos con los términos de terceros (incluido WPML u otros proveedores).

Usalo bajo tu propia responsabilidad. En particular, el puente que evita créditos ATE de WPML puede no estar permitido por la licencia o los términos de WPML en tu caso: verificá eso antes de usarlo en producción.

## Licencia

Distribuido bajo la licencia [MIT](LICENSE).

## Autor

**Raúl Benitez Netto**  
Contacto: [raulbeni@gmail.com](mailto:raulbeni@gmail.com)

## Contribuciones

Issues y pull requests son bienvenidos. Preferí cambios pequeños, con descripción clara del problema y cómo probaron el arreglo.

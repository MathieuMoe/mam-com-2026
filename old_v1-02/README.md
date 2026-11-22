# Portfolio Mathieu Moreau — mam-com.com

Web personal trilingüe (FR / EN / ES) hecha a mano en HTML5, CSS3 y JavaScript vanilla, con backend PHP + MySQL para alojamiento OVH.

## Estructura

```
index.html              Página única (texto FR en el HTML = mejor SEO)
assets/css/style.css    Todo el diseño (variables, modo oscuro, modo código, responsive)
assets/js/i18n.js       Traducciones EN y ES (el FR se lee directamente del HTML)
assets/js/main.js       Efectos, demo EduVerse, formulario, analítica, paleta Ctrl+K
assets/docs/            CV en PDF
assets/img/favicon.svg
api/                    Endpoints PHP (track, contact, campaign) + config
admin/index.php         Panel privado: estadísticas, mensajes, links para reclutadores
sql/schema.sql          Tablas MySQL
.htaccess               HTTPS, seguridad, caché, compresión
```

## Instalación en OVH (≈ 15 min)

1. **PHP ≥ 8.1**: Manager OVH › Web Cloud › Alojamientos › tu alojamiento › pestaña *Información general* › "Versión global de PHP" → 8.2 o superior.
2. **Base de datos**: pestaña *Bases de datos* › Crear una base de datos (MySQL). Anota host (`xxxx.mysql.db`), nombre, usuario y contraseña.
3. **Importar las tablas**: abre phpMyAdmin desde el Manager › pestaña *Importar* › `sql/schema.sql`.
4. **Configuración**: copia `api/config.sample.php` como `api/config.php` y rellena:
   - los datos de la base,
   - `mail_from`: una dirección de tu dominio (ej. `contact@mam-com.com`) — OVH rechaza `mail()` con otro remitente,
   - `salt`: una cadena aleatoria larga,
   - `admin_password_hash`: deja el valor vacío, sube los archivos, abre `https://mam-com.com/admin/?hash=TuContraseña` **una sola vez**, copia el resultado en el config y vuelve a subirlo.
5. **Subir por FTP** (FileZilla) todo el contenido de esta carpeta dentro de `www/`.
6. **SSL**: pestaña *Multisitio* › activa el SSL gratuito Let's Encrypt (el `.htaccess` fuerza HTTPS).
7. Abre `https://mam-com.com/admin/` y conéctate.

## Funciones destacadas

| Función | Para qué sirve |
|---|---|
| **Links para reclutadores** (`/?c=empresa`) | Desde el admin creas un link por empresa. La web la saluda por su nombre ("Bonjour Acme 👋"), puede abrirse directamente en su idioma, y ves cuándo visita, hasta dónde lee, si descarga el CV o si escribe. Ideal para adjuntar a cada candidatura. |
| **Analítica propia sin cookies** | Visitas, visitantes únicos, idioma, dispositivo, navegador, procedencia, embudo de lectura por sección, tiempo medio, clics en LinkedIn/GitHub/email, descargas de CV. La IP nunca se guarda: se cifra (SHA-256 + salt) y cambia cada día. Respeta "Do Not Track". |
| **Formulario de contacto** | Guarda en base + te envía un email. Anti-spam: honeypot, tiempo mínimo, rate limit. Tipo de contacto (puesto / freelance / particular). |
| **Modo código** (botón ✦ ↔ `</>`) | Muestra la estructura HTML de la página tipo plano técnico: enseña que el diseño está hecho a mano. |
| **Paleta de comandos** `Ctrl/⌘ + K` | Navegación, idioma, tema, copiar email, descargar CV… guiño a los perfiles técnicos. |
| **Demo EduVerse interactiva** | Quiz con XP, péndulo con física real (T = 2π√(L/g)), mapa mental, flashcard, pódcast con la voz del navegador. |

## Personalizar

- **Textos**: el francés está en `index.html`; inglés y español en `assets/js/i18n.js` (mismas claves `data-i18n`).
- **Colores**: variables al inicio de `style.css` (`--orange`, `--lime`, `--violet`…).
- **Estado "Disponible"**: texto `nav.status` en el HTML y en `i18n.js`.
- **CV en inglés/español**: añade `CV-Mathieu-Moreau-EN.pdf` / `-ES.pdf` en `assets/docs/` y cambia el enlace en el HTML.
- **Foto**: no hay foto por defecto; se puede añadir en el hero o en la sección de contacto.

## RGPD

Sin cookies ni servicios externos de analítica, IP anonimizada, botón "Purger > 13 mois" en el admin (duración recomendada por la CNIL). Recomendable añadir una pequeña página de "Mentions légales" (obligatoria en Francia para un sitio profesional).

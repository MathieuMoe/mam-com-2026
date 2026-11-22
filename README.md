# mam-com.com — portfolio (web nueva "Spectrum" + versión anterior en /old_v1-02/)

Contenido de la web principal (servicios, EduVerse, realizaciones, trayectoria, método, contacto, Ctrl+K, modo código, tema claro/oscuro)
+ «Mi caja de herramientas» y «¿Tienes preguntas?» (asistente IA), con 3D al servicio del contenido:

**Diseño "Spectrum"** (rediseño completo del frontend): un prisma separa la luz blanca (diseño × código) en los 7 colores del arcoíris. Oscuro por defecto, modo claro, modo código.

| Sección | 3D / efecto |
|---|---|
| Carga | Prisma que dispersa la luz en 7 colores + contador |
| Portada | **WebGL escrito a mano** (shader raymarching): burbujas iridiscentes que siguen el ratón; nombre con grosor variable según el cursor; pegatinas que se lanzan con inercia e inclinación 3D |
| Menú | Pantalla completa: títulos con efecto "roll" arcoíris + **cilindro 3D** de tarjetas que gira hacia la sección |
| 01 Enfoque | Texto que se revela palabra a palabra (V1) + cifras que giran en 3D |
| 02 Servicios | Bento con inclinación 3D y borde arcoíris animado + selector Empresas / Particulares |
| 03 EduVerse | Maqueta 3D con paralaje + péndulo three.js (sin cambios) |
| 03b Suite MatGPT | 8 tarjetas **holográficas** (efecto foil) con enlace a cada repositorio de GitHub |
| 04 Realizaciones | Tarjetas apiladas de la V1 que se hunden en 3D + 4 conceptos de diseño |
| 05 Trayectoria | Cámara 3D a través del tiempo (sin cambios) + formación y certificaciones |
| 06 Método | **Cubo 3D** que gira de una etapa a otra con el scroll |
| 07 Herramientas | Constelación 3D (clic = la estrella viene al frente) + órbitas + medidor circular + barras por área + idiomas |
| 08 Pregúntame / 09 Contacto | Bordes arcoíris animados, auroras |

Si el ordenador del visitante es lento (< 24 fps), la portada cambia sola a una versión CSS. Con "reducir movimiento" no hay animaciones.

Textos nuevos del rediseño (menú, cubo, herramientas…): `app/i18n/extra.php`.
Textos: `app/i18n/{fr,en,es}.php` (generados desde `index.html` + `assets/js/i18n.js` de la web principal).
Textos del asistente, caja de herramientas y privacidad: `app/content/{fr,en,es}.php`. Competencias y niveles: `app/profile.php`.


## Estructura en OVH (desde noviembre 2026)

La web nueva ("Spectrum") está en la **raíz** y la versión anterior se conserva en **/old_v1-02/**.

```
www/
├── index.php, .htaccess, .ovhconfig, robots.txt
├── assets/       css, js (three.js, GSAP, Lenis alojados aquí), fuentes, imágenes
│   └── img/nabbu/   ← capturas de Nabbü (01-dashboard.webp, 02-alumnos.webp…): aparecen solas en la galería 3D
├── api/          track, contact, cv, ask  +  config.php (ÚNICO archivo de configuración de todo el sitio)
├── admin/        panel de la web nueva
├── app/          PRIVADO (bloqueado): textos, perfil, CV PDF, asistente, copias de seguridad
├── sql/          PRIVADO: schema.sql (tablas base) + v2-tables.sql
└── old_v1-02/          versión anterior (index.html, assets, api, admin) — noindex
    └── api/config.php  → solo redirige a /api/config.php
```

- `/v2/...` redirige automáticamente a `/` (301), así los enlaces antiguos siguen funcionando.
- Enlaces para reclutadores: `https://mam-com.com/?c=empresa` (nueva) y `/old_v1-02/?c=empresa` (antigua). Se crean en `/old_v1-02/admin/` (panel completo: mensajes, enlaces, estadísticas de las dos versiones). `/admin/` es el panel de la web nueva (preguntas al asistente, CV descargados, competencias a resaltar).
- Las dos versiones comparten la misma base de datos y la misma `api/config.php`.

## Capturas de Nabbü

Pon las imágenes en `assets/img/nabbu/` con un número delante para el orden. El nombre (sin número) da el título traducido:
`dashboard`, `alumnos`, `cursos`, `profesores`, `facturacion`, `clases`, `contenidos`, `estadisticas`, `calendario`, `alumno`.
Formato recomendado: WebP o JPG, 1600 px de ancho. **Usa datos de demo**: no publiques nombres, emails ni teléfonos reales.

## Fuentes del 3D

`app/src/lab3d.js` es el código legible de `assets/js/lab3d.js` (three.js r170, solo se carga al abrir el laboratorio).
Regenerar: `npm i three@0.170.0 esbuild` y `npx esbuild app/src/lab3d.js --bundle --minify --format=esm --outfile=assets/js/lab3d.js`.
El carrusel, el camino y la constelación son CSS 3D + JavaScript (texto nítido y accesible, sin WebGL).

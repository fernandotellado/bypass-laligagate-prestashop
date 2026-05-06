# Bypass LaLigaGate – Módulo PrestaShop

[![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7%20%7C%208.x-blue.svg)](https://www.prestashop.com/)
[![Versión](https://img.shields.io/badge/versión-1.0.1-green.svg)](bypasslaligagate/bypasslaligagate.php)
[![Licencia](https://img.shields.io/badge/licencia-GPLv2%2B-orange.svg)](LICENSE)

Módulo de PrestaShop que desactiva automáticamente el proxy de Cloudflare cuando hay bloqueos de IP por partidos de fútbol en España, y lo restaura en cuanto el bloqueo termina.

Es la versión PrestaShop del plugin de WordPress [Bypass LaLigaGate](https://es.wordpress.org/plugins/bypass-laligagate/).

## El problema

Durante los partidos de fútbol, los operadores españoles bloquean rangos enteros de IPs de Cloudflare por orden judicial impulsada por LaLiga. Si tu tienda tiene Cloudflare como proxy (modo *Proxied*), tus clientes pueden ver tu web caída sin que tú hayas tocado nada.

Más contexto sobre LaLigaGate: [hayahora.futbol](https://hayahora.futbol/).

## Qué hace el módulo

- Consulta [hayahora.futbol](https://hayahora.futbol/) para saber si hay un bloqueo activo.
- Si lo hay, conmuta los registros DNS seleccionados de **Proxied (CDN)** a **DNS Only** vía API de Cloudflare.
- Cuando el bloqueo termina, vuelve a activar el proxy.
- Lo hace de forma desatendida con un cron (real o "tipo WP-Cron") y respeta un cooldown configurable.
- Puede enviar avisos por email cada vez que conmuta y resúmenes diarios o semanales.

## Compatibilidad

- **PrestaShop**: probado en **8.2.1**, compatibilidad declarada desde **1.7.x**.
- **PHP**: 7.2 o superior (lo requerido por PrestaShop 1.7+).
- **Cloudflare**: cuenta gratuita basta. Recomendado usar API Token con permisos limitados a la zona.

## Instalación

### Vía back office

1. Descarga el ZIP de la última [release](../../releases) o genera uno comprimiendo la carpeta `bypasslaligagate/`.
2. En tu PrestaShop ve a **Módulos → Gestor de módulos → Subir un módulo**.
3. Selecciona el ZIP, instálalo y pulsa **Configurar**.

### Vía FTP

1. Sube la carpeta `bypasslaligagate/` a `/modules/` de tu PrestaShop.
2. En el back office: **Módulos → Gestor de módulos**, busca *Bypass LaLigaGate* y pulsa **Instalar**.

## Configuración rápida

1. Pega tus credenciales de Cloudflare (API Token recomendado) y el Zone ID.
2. Pulsa **Probar conexión y cargar DNS**.
3. Marca los registros DNS que quieres que el módulo conmute (lo habitual: dominio raíz y `www`).
4. Ajusta intervalo de comprobación y cooldown.
5. Si quieres avisos, activa las notificaciones por email.
6. Para que funcione sin depender del tráfico, configura el cron externo del que se da la URL en la propia pantalla.

La documentación completa con el detalle de cada opción está en [bypasslaligagate/README.md](bypasslaligagate/README.md).

## Estructura del repositorio

```
.
├── bypasslaligagate/        Código del módulo PrestaShop
├── LICENSE                  GPL v2 o posterior
├── CHANGELOG.md             Historial de versiones
└── README.md                Este archivo
```

## Cómo contribuir

Las incidencias y propuestas de mejora van en [Issues](../../issues). Si quieres mandar un parche, abre un Pull Request contra `main` con una descripción clara del problema que resuelve.

Antes de enviar cambios, comprueba que:

- El módulo sigue instalando y desinstalando sin errores en una tienda limpia.
- No has roto la compatibilidad con PrestaShop 1.7.x salvo que esté justificado.
- Los textos visibles para el usuario están en `$this->l('...')` para poder traducirlos.

## Versiones y cambios

Mira [CHANGELOG.md](CHANGELOG.md).

## Licencia

GPL v2 o posterior. Consulta [LICENSE](LICENSE).

## Autor

Fernando Tellado – [Mantenimiento WordPress](https://ayudawp.com)

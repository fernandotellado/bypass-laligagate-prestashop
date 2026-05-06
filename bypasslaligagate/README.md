# Bypass LaLigaGate – Módulo PrestaShop

Versión PrestaShop del plugin WordPress [Bypass LaLigaGate](https://github.com/...). Gestiona automáticamente el proxy de Cloudflare durante los bloqueos de IP por partidos de fútbol en España, alternando entre **Proxied (CDN)** y **DNS Only**.

Probado en **PrestaShop 8.2.1** (compatibilidad declarada desde 1.7.x).

## Instalación

1. Comprime la carpeta `bypasslaligagate/` en un ZIP llamado `bypasslaligagate.zip`.
2. En el back office: **Módulos → Gestor de módulos → Subir un módulo**.
3. Instálalo y pulsa **Configurar**.

> Si lo subes vía FTP, copia la carpeta `bypasslaligagate` dentro de `/modules/` y luego instálalo desde **Gestor de módulos**.

## Configuración

En la pantalla de configuración del módulo:

1. **Credenciales de Cloudflare** – API Token (recomendado) o Global API Key + email + Zone ID.
2. **Probar conexión y cargar DNS** – carga los registros de tu zona.
3. **Marca los registros DNS** que quieres que se conmuten (normalmente el dominio raíz y `www`) y pulsa **Guardar cambios**.
4. **Automatización** – ajusta intervalo (5–60 min) y cooldown.
5. **Cron externo** (recomendado) – copia la URL con token y añádela a `crontab`.
6. **Resumen por email** – activa los informes diario/semanal si quieres.

### Cron externo

Igual que en WordPress hay dos modos:

- **Modo "WP-Cron"**: el módulo se engancha al hook `actionDispatcherBefore` del front, así cada visita a la tienda dispara la comprobación si ha pasado el intervalo. Útil sin configuración extra, pero depende del tráfico.
- **Cron real**: configura un cron del servidor que llame a la URL pública del módulo:

```
*/15 * * * * curl -s "https://tu-tienda.com/index.php?fc=module&module=bypasslaligagate&controller=cron&token=XXXX" > /dev/null 2>&1
```

La URL aparece ya generada en la pantalla de configuración.

## Estructura del módulo

```
bypasslaligagate/
├── bypasslaligagate.php              Clase principal Module
├── classes/
│   ├── BlgCloudflareApi.php          Wrapper REST de Cloudflare (cURL)
│   ├── BlgBlockChecker.php           Consulta hayahora.futbol (TXT/JSON)
│   ├── BlgCronManager.php            Lógica de comprobación + cooldown + log
│   └── BlgEmailNotifier.php          Notificaciones via Mail::Send
├── controllers/
│   ├── admin/
│   │   └── AdminBypassLaLigaGateController.php   AJAX desde la UI admin
│   └── front/
│       └── cron.php                  Endpoint público con token para crontab
├── views/
│   ├── templates/admin/configure.tpl Plantilla Smarty de configuración
│   ├── js/admin.js                   JS de back office
│   └── css/admin.css                 Estilos de back office
└── mails/
    ├── es/                           Plantillas de email en español
    └── en/                           Idénticas (fallback obligatorio en PS)
```

## Cómo difiere del plugin WordPress

- **Cron**: WordPress dispara `wp_schedule_event`. PrestaShop no tiene un equivalente nativo, así que se usa el hook `actionDispatcherBefore` con un control de intervalo (`BLG_LAST_TICK`). Para tiendas con poco tráfico, el cron externo es la opción recomendada (igual que en WP).
- **Configuración**: WordPress guarda arrays serializados en `wp_options`. Aquí cada valor escalar va en `Configuration::updateValue()`, y los arrays (registros seleccionados, log, cache DNS, estado) se guardan como JSON.
- **AJAX**: WordPress usa `admin-ajax.php` con nonce. Aquí se usa un `ModuleAdminController` (registrado como tab oculta) con el `tokenAdmin` de PrestaShop.
- **Emails**: `wp_mail()` se reemplaza por `Mail::Send()` con plantillas HTML/TXT en `mails/es/` y `mails/en/`.
- **Desinstalación**: la opción "Borrado de datos" funciona igual; al desinstalar limpia todas las claves `BLG_*` de `ps_configuration`.

## Datos guardados en `ps_configuration`

| Clave | Contenido |
|-------|-----------|
| `BLG_AUTH_TYPE` | `global` o `token` |
| `BLG_CF_EMAIL` | Email de Cloudflare (sólo Global API Key) |
| `BLG_CF_API_KEY` | API Key o Token |
| `BLG_CF_ZONE_ID` | ID de zona |
| `BLG_CHECK_INTERVAL` | Intervalo (5–60 min) |
| `BLG_COOLDOWN` | Periodo de espera (5–600 min) |
| `BLG_SELECTED_RECORDS` | JSON con IDs de registros DNS |
| `BLG_CRON_SECRET` | Token del cron externo |
| `BLG_MIN_ISPS` | ISPs mínimos para activar |
| `BLG_EMAIL_NOTIF` | Avisos por email (0/1) |
| `BLG_NOTIF_EMAIL` | Email para los avisos |
| `BLG_DELETE_DATA` | Borrar todo al desinstalar (0/1) |
| `BLG_SUMMARY_*` | Resumen periódico (enabled/freq/time/last_ts) |
| `BLG_DNS_CACHE` | JSON cache de registros |
| `BLG_STATE` | JSON estado del bypass |
| `BLG_BLOCK_LOG` | JSON log de episodios |
| `BLG_LAST_TICK` | Último disparo del cron oportunista |

## Licencia

GPL v2 o posterior.

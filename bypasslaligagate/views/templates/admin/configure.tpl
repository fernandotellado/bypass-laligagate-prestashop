{*
* Bypass LaLigaGate - Plantilla de configuración
*
* @author    Mantenimiento WordPress <fernando@tellado.es>
* @copyright 2026 Mantenimiento WordPress
* @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
*}
<link rel="stylesheet" href="{$blg_module_dir|escape:'htmlall':'UTF-8'}views/css/admin.css?v={$blg_version|escape:'htmlall':'UTF-8'}" />

<div class="ayudawp-blg-wrap">
	<h1>Bypass LaLigaGate <small>v{$blg_version|escape:'htmlall':'UTF-8'}</small></h1>
	<p class="text-muted">Gestiona el proxy de Cloudflare automáticamente durante los bloqueos de IP por partidos de fútbol en España.</p>

	{if $blg_diag.unhealthy && $blg_has_checked}
		<div class="alert alert-warning ayudawp-blg-cron-warning">
			<strong>La comprobación automática se está retrasando.</strong>
			{if !$blg_diag.scheduled}
				No hay ninguna comprobación programada. Vuelve a guardar los ajustes para reprogramarla.
			{elseif $blg_diag.stale}
				La última comprobación fue hace {($blg_diag.seconds_since/60)|intval} min (intervalo configurado: {$blg_config.BLG_CHECK_INTERVAL|intval} min).
			{else}
				La próxima comprobación debería haberse ejecutado ya.
			{/if}
			El cron del módulo solo se dispara con visitas al sitio. Si tu tienda tiene poco tráfico usa el <strong>cron externo</strong> que aparece más abajo para ejecutar la comprobación desde el servidor.
		</div>
	{/if}

	<div class="ayudawp-blg-card ayudawp-blg-card--status">
		<h2>Estado actual</h2>
		<table class="ayudawp-blg-status-table">
			<tr>
				<th>Bloqueos activos</th>
				<td id="blg-status-blocked">
					<span class="blg-badge {if $blg_is_blocked}blg-badge-danger{else}blg-badge-ok{/if}">{if $blg_is_blocked}SI{else}NO{/if}</span>
				</td>
				<td class="blg-status-detail">
					{if $blg_is_blocked}
						Hay partidos con bloqueos activos.
					{elseif $blg_has_checked}
						No se detectan bloqueos en este momento.
					{else}
						Aún no se ha realizado ninguna comprobación.
					{/if}
					<a href="https://hayahora.futbol/" target="_blank" rel="noopener">Ver hayahora.futbol</a>
				</td>
			</tr>
			<tr>
				<th>Bypass activo (proxy OFF)</th>
				<td id="blg-status-bypass">
					<span class="blg-badge {if $blg_is_bypass}blg-badge-warning{else}blg-badge-ok{/if}">{if $blg_is_bypass}SI{else}NO{/if}</span>
				</td>
				<td class="blg-status-detail">
					{if $blg_is_manual}
						Forzado manualmente. Pulsa "Restaurar proxy ON" para devolver el control al cron.
					{elseif $blg_is_bypass}
						Activado automáticamente por detección de bloqueos.
					{else}
						Proxy activo (CDN). Funcionamiento normal.
					{/if}
				</td>
			</tr>
			<tr>
				<th>Última comprobación</th>
				<td id="blg-status-lastcheck" colspan="2">
					{if $blg_has_checked}{$blg_state.last_check|escape:'htmlall':'UTF-8'}{else}Pendiente{/if}
					{if $blg_has_checked && $blg_state.last_source}
						<span class="blg-status-note">(fuente: {$blg_state.last_source|escape:'htmlall':'UTF-8'})</span>
					{/if}
					{if $blg_has_checked && $blg_diag.stale}
						<span class="blg-badge blg-badge-warning blg-cron-badge">retrasada</span>
					{/if}
				</td>
			</tr>
			<tr>
				<th>Próxima comprobación</th>
				<td id="blg-status-nextcheck" colspan="2">
					{$blg_diag.next_human|escape:'htmlall':'UTF-8'}
					<span class="blg-status-note">(cada {$blg_config.BLG_CHECK_INTERVAL|intval} min en cada visita al front)</span>
				</td>
			</tr>
		</table>
		<div class="ayudawp-blg-manual-actions">
			<button type="button" class="btn btn-default" id="blg-btn-check">Comprobar ahora</button>
			<button type="button" class="btn btn-default" id="blg-btn-proxy-off">Forzar proxy OFF</button>
			<button type="button" class="btn btn-default" id="blg-btn-proxy-on">Restaurar proxy ON</button>
		</div>
		<div id="blg-action-status" class="ayudawp-blg-action-msg"></div>
	</div>

	<form method="post" action="{$blg_form_action|escape:'htmlall':'UTF-8'}" id="blg-form">
		<input type="hidden" name="submit_blg_save" value="1" />

		<div class="ayudawp-blg-card">
			<h2>Credenciales de Cloudflare</h2>
			<table class="form-table">
				<tr>
					<th scope="row">Tipo de autenticación</th>
					<td>
						<select name="auth_type" id="blg-field-auth-type">
							<option value="global" {if $blg_config.BLG_AUTH_TYPE == 'global'}selected="selected"{/if}>Global API Key</option>
							<option value="token" {if $blg_config.BLG_AUTH_TYPE == 'token'}selected="selected"{/if}>API Token</option>
						</select>
						<div class="blg-help-box" id="blg-help-auth-global"><p>Ya existe en tu cuenta, solo hay que copiarla. Tiene acceso completo.</p></div>
						<div class="blg-help-box" id="blg-help-auth-token" style="display:none;"><p>Más seguro: permite limitar permisos a lo necesario.</p></div>
					</td>
				</tr>
				<tr id="blg-row-email">
					<th scope="row">Email de Cloudflare</th>
					<td>
						<input type="email" name="cf_email" id="blg-field-email" value="{$blg_config.BLG_CF_EMAIL|escape:'htmlall':'UTF-8'}" class="form-control" />
						<p class="text-muted">El email con el que te registraste en Cloudflare.</p>
					</td>
				</tr>
				<tr>
					<th scope="row" id="blg-label-apikey">Global API Key</th>
					<td>
						<input type="password" name="cf_api_key" id="blg-field-apikey" value="{$blg_config.BLG_CF_API_KEY|escape:'htmlall':'UTF-8'}" class="form-control" autocomplete="off" />
						<div class="blg-help-box" id="blg-help-apikey-global">
							<p><strong>Dónde encontrarla:</strong></p>
							<ol>
								<li>Entra en <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Mi perfil &rarr; API Tokens</a></li>
								<li>Junto a "Global API Key", pulsa <strong>Ver</strong></li>
								<li>Confirma tu contraseña y copia la clave</li>
							</ol>
						</div>
						<div class="blg-help-box" id="blg-help-apikey-token" style="display:none;">
							<p><strong>Cómo crear el token:</strong></p>
							<ol>
								<li>Ve a <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Mi perfil &rarr; API Tokens</a> &rarr; <strong>Crear token</strong></li>
								<li>Usa la plantilla <strong>"Editar zona DNS"</strong></li>
								<li>En Permisos: <code>Zona &gt; DNS &gt; Editar</code> + añade <code>Zona &gt; Zona &gt; Leer</code></li>
								<li>En Recursos de zona: <strong>Incluir &gt; Zona específica</strong> &gt; selecciona el dominio</li>
								<li>Filtro de IP y TTL: déjalos sin configurar</li>
								<li>Pulsa "Ir al resumen", confirma, y copia el token (solo se muestra una vez)</li>
							</ol>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row">ID de zona</th>
					<td>
						<input type="text" name="cf_zone_id" id="blg-field-zoneid" value="{$blg_config.BLG_CF_ZONE_ID|escape:'htmlall':'UTF-8'}" class="form-control" />
						<div class="blg-help-box"><p>Dashboard de Cloudflare del dominio &rarr; panel derecho de Overview &rarr; sección "API". Código de 32 caracteres.</p></div>
					</td>
				</tr>
				<tr>
					<th scope="row">Conexión</th>
					<td>
						<button type="button" class="btn btn-primary" id="blg-btn-test">Probar conexión y cargar DNS</button>
						<div id="blg-test-status" class="ayudawp-blg-action-msg"></div>
					</td>
				</tr>
			</table>
		</div>

		<div class="ayudawp-blg-card">
			<h2>Registros DNS a gestionar</h2>
			<p class="blg-important-note">Marca los registros cuyo proxy quieres que se desactive durante los bloqueos (normalmente el dominio raíz y www) y pulsa "Guardar cambios" más abajo.</p>
			<div id="blg-dns-records">{$blg_dns_table_html nofilter}</div>
		</div>

		<div class="ayudawp-blg-card">
			<h2>Automatización</h2>
			<table class="form-table">
				<tr>
					<th scope="row">Intervalo de comprobación</th>
					<td>
						<input type="number" name="check_interval" value="{$blg_config.BLG_CHECK_INTERVAL|intval}" min="5" max="60" class="form-control" style="width:80px;display:inline-block;" /> minutos
						<p class="text-muted">Cada cuánto se consulta hayahora.futbol (5-60 min). En PrestaShop la comprobación se dispara con cada visita al front si ha pasado el intervalo, igual que WP-Cron en WordPress.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Periodo de espera tras desactivar</th>
					<td>
						<input type="number" name="cooldown" value="{$blg_config.BLG_COOLDOWN|intval}" min="5" max="600" class="form-control" style="width:80px;display:inline-block;" /> minutos
						<p class="text-muted">Espera antes de reactivar el proxy tras terminar los bloqueos (5-600 min).</p>
					</td>
				</tr>
				<tr {if $blg_diag.unhealthy && $blg_has_checked}class="blg-row-highlight"{/if}>
					<th scope="row">
						Cron externo (recomendado)
						{if $blg_diag.unhealthy && $blg_has_checked}<br /><span class="blg-badge blg-badge-warning">recomendado</span>{/if}
					</th>
					<td>
						<input type="text" name="cron_secret" value="{$blg_config.BLG_CRON_SECRET|escape:'htmlall':'UTF-8'}" class="form-control" autocomplete="off" />
						<div class="blg-help-box">
							<p>Si quieres usar un cron real del servidor en vez de depender de las visitas al front, configura esta URL:</p>
							<code class="blg-code-block">{$blg_cron_url|escape:'htmlall':'UTF-8'}</code>
							<p>Ejemplo para crontab (cada {$blg_config.BLG_CHECK_INTERVAL|intval} min):</p>
							<code class="blg-code-block">*/{$blg_config.BLG_CHECK_INTERVAL|intval} * * * * curl -s "{$blg_cron_url|escape:'htmlall':'UTF-8'}" &gt; /dev/null 2&gt;&amp;1</code>
							<p>Para regenerar el token, borra el campo y guarda.</p>
						</div>
					</td>
				</tr>
			</table>
		</div>

		<div class="ayudawp-blg-card">
			<h2>Opciones generales</h2>
			<table class="form-table">
				<tr>
					<th scope="row">ISPs mínimos para activar bypass</th>
					<td>
						<input type="number" name="min_isps" value="{$blg_config.BLG_MIN_ISPS|intval}" min="1" class="form-control" style="width:80px;display:inline-block;" />
						<p class="text-muted">Número mínimo de proveedores (ISPs) distintos con bloqueos activos para considerar que hay un bloqueo real de La Liga. Valor recomendado: 2.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Email para avisos</th>
					<td>
						<input type="email" name="notif_email" value="{$blg_config.BLG_NOTIF_EMAIL|escape:'htmlall':'UTF-8'}" class="form-control" />
						<p class="text-muted">Si lo dejas vacío se usará el email de la tienda (PS_SHOP_EMAIL).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Avisos por email</th>
					<td>
						<label>
							<input type="checkbox" name="email_notifications" value="1" {if $blg_config.BLG_EMAIL_NOTIF}checked="checked"{/if} />
							Enviar un email cuando el proxy se active o desactive automáticamente
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Borrado de datos</th>
					<td>
						<label>
							<input type="checkbox" name="delete_data" value="1" {if $blg_config.BLG_DELETE_DATA}checked="checked"{/if} />
							Eliminar todos los datos del módulo de la base de datos al desinstalarlo
						</label>
						<p class="text-muted">Si desmarcas esta opción, la configuración se conservará para una futura reinstalación.</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="ayudawp-blg-card">
			<h2>Resumen por email</h2>
			<table class="form-table">
				<tr>
					<th scope="row">Enviar resumen periódico</th>
					<td>
						<label>
							<input type="checkbox" name="summary_enabled" value="1" {if $blg_config.BLG_SUMMARY_ENABLED}checked="checked"{/if} />
							Enviar un resumen por email con el tiempo que la web habría estado inaccesible si no fuese por el bypass
						</label>
						<p class="text-muted">Solo se cuenta el tiempo de bloqueos reales detectados en hayahora.futbol; no incluye el periodo de espera posterior.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Frecuencia</th>
					<td>
						<select name="summary_frequency" class="form-control" style="width:auto;display:inline-block;">
							<option value="weekly" {if $blg_config.BLG_SUMMARY_FREQ == 'weekly'}selected="selected"{/if}>Semanal</option>
							<option value="daily" {if $blg_config.BLG_SUMMARY_FREQ == 'daily'}selected="selected"{/if}>Diaria</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">Hora de envío</th>
					<td>
						<input type="time" name="summary_time" value="{$blg_config.BLG_SUMMARY_TIME|escape:'htmlall':'UTF-8'}" class="form-control" style="width:auto;display:inline-block;" />
						<p class="text-muted">Se usa la zona horaria del servidor. Los partidos de La Liga nunca son por la mañana, por eso por defecto se envía a las 10:00.</p>
					</td>
				</tr>
			</table>
		</div>

		<div class="panel-footer">
			<button type="submit" class="btn btn-default pull-right">
				<i class="process-icon-save"></i> Guardar cambios
			</button>
		</div>
	</form>

	<p class="ayudawp-blg-footer">Bypass LaLigaGate v{$blg_version|escape:'htmlall':'UTF-8'} &mdash; <a href="https://mantenimiento.ayudawp.com" target="_blank" rel="noopener">Mantenimiento WordPress</a></p>
</div>

<script>
window.ayudawpBlg = {
	ajaxUrl: {$blg_ajax_url|json_encode nofilter},
	token: {$blg_admin_token|json_encode nofilter}
};
</script>
<script src="{$blg_module_dir|escape:'htmlall':'UTF-8'}views/js/admin.js?v={$blg_version|escape:'htmlall':'UTF-8'}"></script>

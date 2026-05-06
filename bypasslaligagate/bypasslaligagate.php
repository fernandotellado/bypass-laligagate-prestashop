<?php
/**
 * Bypass LaLigaGate - Módulo PrestaShop
 *
 * Gestiona automáticamente el proxy de Cloudflare durante los bloqueos de IP
 * por partidos de fútbol en España, alternando entre Proxied (CDN) y DNS Only.
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/BlgCloudflareApi.php';
require_once __DIR__ . '/classes/BlgBlockChecker.php';
require_once __DIR__ . '/classes/BlgEmailNotifier.php';
require_once __DIR__ . '/classes/BlgCronManager.php';

class BypassLaLigaGate extends Module
{
    const CONF_AUTH_TYPE       = 'BLG_AUTH_TYPE';
    const CONF_CF_EMAIL        = 'BLG_CF_EMAIL';
    const CONF_CF_API_KEY      = 'BLG_CF_API_KEY';
    const CONF_CF_ZONE_ID      = 'BLG_CF_ZONE_ID';
    const CONF_CHECK_INTERVAL  = 'BLG_CHECK_INTERVAL';
    const CONF_COOLDOWN        = 'BLG_COOLDOWN';
    const CONF_SELECTED        = 'BLG_SELECTED_RECORDS';
    const CONF_CRON_SECRET     = 'BLG_CRON_SECRET';
    const CONF_MIN_ISPS        = 'BLG_MIN_ISPS';
    const CONF_EMAIL_NOTIF     = 'BLG_EMAIL_NOTIF';
    const CONF_DELETE_DATA     = 'BLG_DELETE_DATA';
    const CONF_NOTIF_EMAIL     = 'BLG_NOTIF_EMAIL';
    const CONF_SUMMARY_ENABLED = 'BLG_SUMMARY_ENABLED';
    const CONF_SUMMARY_FREQ    = 'BLG_SUMMARY_FREQ';
    const CONF_SUMMARY_TIME    = 'BLG_SUMMARY_TIME';
    const CONF_SUMMARY_LAST_TS = 'BLG_SUMMARY_LAST_TS';

    const CONF_DNS_CACHE   = 'BLG_DNS_CACHE';
    const CONF_STATE       = 'BLG_STATE';
    const CONF_BLOCK_LOG   = 'BLG_BLOCK_LOG';
    const CONF_LAST_TICK   = 'BLG_LAST_TICK';

    public function __construct()
    {
        $this->name          = 'bypasslaligagate';
        $this->tab           = 'administration';
        $this->version       = '1.0.0';
        $this->author        = 'Mantenimiento WordPress';
        $this->need_instance = 0;
        $this->bootstrap     = true;

        parent::__construct();

        $this->displayName = $this->l('Bypass LaLigaGate');
        $this->description = $this->l('Gestiona automáticamente el proxy de Cloudflare durante los bloqueos de IP por partidos de fútbol en España, alternando entre Proxied (CDN) y DNS Only.');

        $this->confirmUninstall = $this->l('¿Seguro que quieres desinstalar Bypass LaLigaGate? Si tienes activada la opción "Borrado de datos" se eliminará toda la configuración del módulo.');

        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        foreach ($this->getDefaultConfig() as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        if (!Configuration::get(self::CONF_CRON_SECRET)) {
            Configuration::updateValue(self::CONF_CRON_SECRET, Tools::passwdGen(32, 'NO_NUMERIC'));
        }
        if (!Configuration::get(self::CONF_NOTIF_EMAIL)) {
            Configuration::updateValue(self::CONF_NOTIF_EMAIL, (string) Configuration::get('PS_SHOP_EMAIL'));
        }

        $this->saveState($this->defaultState());

        if (!$this->installTab()) {
            return false;
        }

        return $this->registerHook('actionDispatcherBefore')
            && $this->registerHook('displayBackOfficeHeader');
    }

    public function uninstall()
    {
        $deleteData = (int) Configuration::get(self::CONF_DELETE_DATA);

        $this->uninstallTab();

        if (!parent::uninstall()) {
            return false;
        }

        if ($deleteData) {
            $keys = array_keys($this->getDefaultConfig());
            $keys[] = self::CONF_DNS_CACHE;
            $keys[] = self::CONF_STATE;
            $keys[] = self::CONF_BLOCK_LOG;
            $keys[] = self::CONF_LAST_TICK;
            $keys[] = self::CONF_CRON_SECRET;
            $keys[] = self::CONF_NOTIF_EMAIL;
            $keys[] = self::CONF_SUMMARY_LAST_TS;
            foreach ($keys as $key) {
                Configuration::deleteByName($key);
            }
        }

        return true;
    }

    /**
     * Registra una pestaña admin oculta para que el AdminController quede
     * disponible y se pueda invocar vía AJAX desde la página de configuración.
     */
    protected function installTab()
    {
        $className = 'AdminBypassLaLigaGate';
        if ((int) Tab::getIdFromClassName($className) > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = $className;
        $tab->module     = $this->name;
        $tab->active     = 1;
        $tab->id_parent  = -1;

        $names = [];
        foreach (Language::getLanguages(false) as $lang) {
            $names[(int) $lang['id_lang']] = 'Bypass LaLigaGate';
        }
        $tab->name = $names;

        return (bool) $tab->add();
    }

    protected function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminBypassLaLigaGate');
        if ($idTab > 0) {
            $tab = new Tab($idTab);
            return (bool) $tab->delete();
        }
        return true;
    }

    public function getDefaultConfig()
    {
        return [
            self::CONF_AUTH_TYPE       => 'global',
            self::CONF_CF_EMAIL        => '',
            self::CONF_CF_API_KEY      => '',
            self::CONF_CF_ZONE_ID      => '',
            self::CONF_CHECK_INTERVAL  => 15,
            self::CONF_COOLDOWN        => 60,
            self::CONF_SELECTED        => '[]',
            self::CONF_MIN_ISPS        => 1,
            self::CONF_EMAIL_NOTIF     => 1,
            self::CONF_DELETE_DATA     => 1,
            self::CONF_SUMMARY_ENABLED => 0,
            self::CONF_SUMMARY_FREQ    => 'weekly',
            self::CONF_SUMMARY_TIME    => '10:00',
        ];
    }

    public function getConfig()
    {
        $defaults = $this->getDefaultConfig();
        $config = [];
        foreach ($defaults as $key => $default) {
            $val = Configuration::get($key);
            $config[$key] = ($val === false) ? $default : $val;
        }

        $config[self::CONF_CRON_SECRET] = (string) Configuration::get(self::CONF_CRON_SECRET);
        $config[self::CONF_NOTIF_EMAIL] = (string) Configuration::get(self::CONF_NOTIF_EMAIL);
        $config[self::CONF_SUMMARY_LAST_TS] = (int) Configuration::get(self::CONF_SUMMARY_LAST_TS);

        $selected = json_decode((string) $config[self::CONF_SELECTED], true);
        $config[self::CONF_SELECTED] = is_array($selected) ? $selected : [];

        $config[self::CONF_CHECK_INTERVAL] = max(5, min(60, (int) $config[self::CONF_CHECK_INTERVAL]));
        $config[self::CONF_COOLDOWN]       = max(5, min(600, (int) $config[self::CONF_COOLDOWN]));
        $config[self::CONF_MIN_ISPS]       = max(1, (int) $config[self::CONF_MIN_ISPS]);
        $config[self::CONF_EMAIL_NOTIF]    = (int) $config[self::CONF_EMAIL_NOTIF] ? 1 : 0;
        $config[self::CONF_DELETE_DATA]    = (int) $config[self::CONF_DELETE_DATA] ? 1 : 0;
        $config[self::CONF_SUMMARY_ENABLED] = (int) $config[self::CONF_SUMMARY_ENABLED] ? 1 : 0;

        if (!in_array($config[self::CONF_AUTH_TYPE], ['global', 'token'], true)) {
            $config[self::CONF_AUTH_TYPE] = 'global';
        }
        if (!in_array($config[self::CONF_SUMMARY_FREQ], ['daily', 'weekly'], true)) {
            $config[self::CONF_SUMMARY_FREQ] = 'weekly';
        }
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', (string) $config[self::CONF_SUMMARY_TIME])) {
            $config[self::CONF_SUMMARY_TIME] = '10:00';
        }

        return $config;
    }

    public function defaultState()
    {
        return [
            'bypass_active'   => 0,
            'bypass_since'    => 0,
            'blocks_ended_at' => 0,
            'manual_override' => 0,
            'last_check'      => '',
            'last_check_ts'   => 0,
            'last_status'     => 'NO',
            'last_source'     => '',
        ];
    }

    public function getState()
    {
        $raw = Configuration::get(self::CONF_STATE);
        $st  = $raw ? json_decode((string) $raw, true) : null;
        if (!is_array($st)) {
            return $this->defaultState();
        }
        return array_merge($this->defaultState(), $st);
    }

    public function saveState(array $state)
    {
        return Configuration::updateValue(self::CONF_STATE, json_encode($state));
    }

    public function getDnsCache()
    {
        $raw = Configuration::get(self::CONF_DNS_CACHE);
        $arr = $raw ? json_decode((string) $raw, true) : [];
        return is_array($arr) ? $arr : [];
    }

    public function saveDnsCache(array $records)
    {
        return Configuration::updateValue(self::CONF_DNS_CACHE, json_encode($records));
    }

    public function getBlockLog()
    {
        $raw = Configuration::get(self::CONF_BLOCK_LOG);
        $arr = $raw ? json_decode((string) $raw, true) : [];
        return is_array($arr) ? $arr : [];
    }

    public function saveBlockLog(array $log)
    {
        return Configuration::updateValue(self::CONF_BLOCK_LOG, json_encode(array_values($log)));
    }

    public function appendBlockEpisode(array $episode)
    {
        $log   = $this->getBlockLog();
        $log[] = $episode;
        if (count($log) > 500) {
            $log = array_slice($log, -500);
        }
        return $this->saveBlockLog($log);
    }

    public static function findRecord(array $records, $recordId)
    {
        foreach ($records as $r) {
            if (isset($r['id']) && $r['id'] === $recordId) {
                return $r;
            }
        }
        return null;
    }

    public function getCronDiagnostics()
    {
        $cfg     = $this->getConfig();
        $state   = $this->getState();
        $intMin  = max(5, min(60, (int) $cfg[self::CONF_CHECK_INTERVAL]));
        $intSecs = $intMin * 60;
        $now     = time();
        $lastTs  = (int) $state['last_check_ts'];
        $lastTick = (int) Configuration::get(self::CONF_LAST_TICK);

        $secondsSince = $lastTs > 0 ? max(0, $now - $lastTs) : 0;
        $stale        = $lastTs > 0 && $secondsSince > (2 * $intSecs);

        $nextTs = $lastTick > 0 ? ($lastTick + $intSecs) : ($now + $intSecs);
        $overdue = $nextTs > 0 && ($now - $nextTs) > 300;

        return [
            'next_ts'       => $nextTs,
            'next_human'    => date('Y-m-d H:i:s', $nextTs),
            'last_check_ts' => $lastTs,
            'seconds_since' => $secondsSince,
            'interval_secs' => $intSecs,
            'scheduled'     => true,
            'stale'         => $stale,
            'overdue'       => $overdue,
            'unhealthy'     => $stale || $overdue,
        ];
    }

    /**
     * Hook frontend: dispara la comprobación de manera oportunista en cada
     * visita, respetando el intervalo configurado. Equivalente a WP-Cron.
     * Se ejecuta sin bloquear la respuesta cuando es posible.
     */
    public function hookActionDispatcherBefore()
    {
        $cfg      = $this->getConfig();
        $intMin   = max(5, min(60, (int) $cfg[self::CONF_CHECK_INTERVAL]));
        $now      = time();
        $lastTick = (int) Configuration::get(self::CONF_LAST_TICK);

        if ($lastTick > 0 && ($now - $lastTick) < ($intMin * 60)) {
            return;
        }

        Configuration::updateValue(self::CONF_LAST_TICK, $now);

        try {
            $cron = new BlgCronManager($this);
            $cron->runCheck();
        } catch (Exception $e) {
            // Nunca debe romper el front
        }
    }

    public function hookDisplayBackOfficeHeader()
    {
        return '';
    }

    /**
     * Página de configuración del módulo en el back office.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submit_blg_save')) {
            $output .= $this->processSettingsSave();
        }

        $cfg   = $this->getConfig();
        $state = $this->getState();
        $dns   = $this->getDnsCache();
        $diag  = $this->getCronDiagnostics();

        $isBlocked = ($state['last_status'] === 'SI');
        $isManual  = !empty($state['manual_override']);
        $isBypass  = !empty($state['bypass_active']) || $isManual;
        $hasChecked = !empty($state['last_check']);

        $cronUrl = $this->context->link->getModuleLink(
            $this->name,
            'cron',
            ['token' => $cfg[self::CONF_CRON_SECRET]],
            true
        );

        $ajaxUrl = $this->context->link->getAdminLink('AdminBypassLaLigaGate');

        $this->context->smarty->assign([
            'blg_module_dir'     => $this->_path,
            'blg_version'        => $this->version,
            'blg_config'         => $cfg,
            'blg_state'          => $state,
            'blg_dns_records'    => $dns,
            'blg_diag'           => $diag,
            'blg_is_blocked'     => $isBlocked,
            'blg_is_manual'      => $isManual,
            'blg_is_bypass'      => $isBypass,
            'blg_has_checked'    => $hasChecked,
            'blg_cron_url'       => $cronUrl,
            'blg_ajax_url'       => $ajaxUrl,
            'blg_admin_token'    => Tools::getAdminTokenLite('AdminBypassLaLigaGate'),
            'blg_form_action'    => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'blg_dns_table_html' => $this->renderDnsTable($dns, $cfg[self::CONF_SELECTED]),
        ]);

        $output .= $this->display(__FILE__, 'views/templates/admin/configure.tpl');
        return $output;
    }

    protected function processSettingsSave()
    {
        $existing = $this->getConfig();

        $auth = Tools::getValue('auth_type', $existing[self::CONF_AUTH_TYPE]);
        Configuration::updateValue(self::CONF_AUTH_TYPE, in_array($auth, ['global', 'token'], true) ? $auth : 'global');

        Configuration::updateValue(self::CONF_CF_EMAIL, trim((string) Tools::getValue('cf_email', $existing[self::CONF_CF_EMAIL])));
        Configuration::updateValue(self::CONF_CF_API_KEY, trim((string) Tools::getValue('cf_api_key', $existing[self::CONF_CF_API_KEY])));
        Configuration::updateValue(self::CONF_CF_ZONE_ID, trim((string) Tools::getValue('cf_zone_id', $existing[self::CONF_CF_ZONE_ID])));

        Configuration::updateValue(self::CONF_CHECK_INTERVAL, max(5, min(60, (int) Tools::getValue('check_interval', $existing[self::CONF_CHECK_INTERVAL]))));
        Configuration::updateValue(self::CONF_COOLDOWN, max(5, min(600, (int) Tools::getValue('cooldown', $existing[self::CONF_COOLDOWN]))));
        Configuration::updateValue(self::CONF_MIN_ISPS, max(1, (int) Tools::getValue('min_isps', $existing[self::CONF_MIN_ISPS])));
        Configuration::updateValue(self::CONF_EMAIL_NOTIF, Tools::getValue('email_notifications') ? 1 : 0);
        Configuration::updateValue(self::CONF_DELETE_DATA, Tools::getValue('delete_data') ? 1 : 0);

        $email = trim((string) Tools::getValue('notif_email', Configuration::get(self::CONF_NOTIF_EMAIL)));
        if ($email === '' || !Validate::isEmail($email)) {
            $email = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        Configuration::updateValue(self::CONF_NOTIF_EMAIL, $email);

        $secret = trim((string) Tools::getValue('cron_secret', Configuration::get(self::CONF_CRON_SECRET)));
        if ($secret === '') {
            $secret = Tools::passwdGen(32, 'NO_NUMERIC');
        }
        Configuration::updateValue(self::CONF_CRON_SECRET, $secret);

        $selected = (array) Tools::getValue('selected_records', []);
        $selected = array_values(array_unique(array_filter(array_map(static function ($v) {
            return preg_replace('/[^a-f0-9]/i', '', (string) $v);
        }, $selected))));
        Configuration::updateValue(self::CONF_SELECTED, json_encode($selected));

        Configuration::updateValue(self::CONF_SUMMARY_ENABLED, Tools::getValue('summary_enabled') ? 1 : 0);
        $freq = Tools::getValue('summary_frequency', $existing[self::CONF_SUMMARY_FREQ]);
        Configuration::updateValue(self::CONF_SUMMARY_FREQ, in_array($freq, ['daily', 'weekly'], true) ? $freq : 'weekly');

        $time = (string) Tools::getValue('summary_time', $existing[self::CONF_SUMMARY_TIME]);
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            $time = '10:00';
        } else {
            $time = str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        }
        Configuration::updateValue(self::CONF_SUMMARY_TIME, $time);

        return $this->displayConfirmation($this->l('Ajustes guardados.'));
    }

    /**
     * Genera la tabla HTML de registros DNS.
     * Devuelve cadena ya escapada para incrustar en la plantilla o respuesta AJAX.
     */
    public function renderDnsTable(array $records, array $selectedIds)
    {
        if (empty($records)) {
            return '<p class="help-block">' . $this->l('No hay registros DNS en caché. Rellena las credenciales y pulsa "Probar conexión y cargar DNS".') . '</p>';
        }

        $html  = '<div class="ayudawp-blg-dns-scroll"><table class="table ayudawp-blg-dns-table">';
        $html .= '<thead><tr>';
        $html .= '<th class="check-column"><input type="checkbox" id="blg-select-all" /></th>';
        $html .= '<th>' . $this->l('Nombre') . '</th>';
        $html .= '<th>' . $this->l('Tipo') . '</th>';
        $html .= '<th>' . $this->l('Contenido') . '</th>';
        $html .= '<th>' . $this->l('Proxy') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($records as $r) {
            $rid = isset($r['id']) ? (string) $r['id'] : '';
            $checked = in_array($rid, $selectedIds, true) ? ' checked="checked"' : '';
            $proxied = !empty($r['proxied']);
            $proxyLabel = $proxied ? 'ON' : 'OFF';
            $cls = $proxied ? 'blg-proxy-on' : 'blg-proxy-off';
            $html .= '<tr>';
            $html .= '<td><input type="checkbox" name="selected_records[]" value="' . htmlspecialchars($rid, ENT_QUOTES, 'UTF-8') . '"' . $checked . ' /></td>';
            $html .= '<td>' . htmlspecialchars((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
            $html .= '<td><code>' . htmlspecialchars((string) ($r['type'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></td>';
            $html .= '<td><code>' . htmlspecialchars((string) ($r['content'] ?? ''), ENT_QUOTES, 'UTF-8') . '</code></td>';
            $html .= '<td><span class="' . $cls . '">' . $proxyLabel . '</span></td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }
}

<?php
/**
 * AdminController para llamadas AJAX desde la página de configuración.
 *
 * Endpoint: index.php?controller=AdminBypassLaLigaGate&token=XXX&ajax=1&action=...
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminBypassLaLigaGateController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        if (!$this->module) {
            $this->module = Module::getInstanceByName('bypasslaligagate');
        }
    }

    public function postProcess()
    {
        if (!$this->ajax) {
            parent::postProcess();
            return;
        }

        $action = (string) Tools::getValue('action');
        if ($action === '') {
            $this->blgSendError('Acción no especificada.');
        }

        $method = 'ajaxProcess' . Tools::toCamelCase($action);
        if (!method_exists($this, $method)) {
            $this->blgSendError('Acción no encontrada: ' . $action);
        }

        $this->{$method}();
    }

    /**
     * Asegura el dispatch ajax si PrestaShop salta directamente a display()
     * sin pasar por nuestro postProcess() (varía entre versiones).
     */
    public function display()
    {
        if (!$this->ajax) {
            parent::display();
            return;
        }
        $action = (string) Tools::getValue('action');
        if ($action === '') {
            $this->blgSendError('Acción no especificada.');
        }
        $method = 'ajaxProcess' . Tools::toCamelCase($action);
        if (!method_exists($this, $method)) {
            $this->blgSendError('Acción no encontrada: ' . $action);
        }
        $this->{$method}();
    }

    public function ajaxProcessTestAndLoad()
    {
        $cfg = $this->blgGetConfigWithFormCreds();

        $api = new BlgCloudflareApi([
            'auth_type'  => $cfg['auth_type'],
            'cf_email'   => $cfg['cf_email'],
            'cf_api_key' => $cfg['cf_api_key'],
            'cf_zone_id' => $cfg['cf_zone_id'],
        ]);

        $test = $api->testConnection();
        if (empty($test['success'])) {
            $this->blgSendError($test['message']);
        }

        $records = $api->fetchDnsRecords();
        if (empty($records)) {
            $this->blgSendSuccess([
                'message' => $test['message'] . ' — Pero no se obtuvieron registros DNS.',
                'html'    => '',
            ]);
        }

        /** @var BypassLaLigaGate $module */
        $module = $this->blgGetModule();
        $module->saveDnsCache($records);

        $selected = $module->getConfig()[BypassLaLigaGate::CONF_SELECTED];
        $html = $module->renderDnsTable($records, $selected);

        $this->blgSendSuccess([
            'message' => $test['message'] . ' — ' . count($records) . ' registros DNS cargados.',
            'html'    => $html,
        ]);
    }

    public function ajaxProcessManualCheck()
    {
        /** @var BypassLaLigaGate $module */
        $module = $this->blgGetModule();
        $cron   = new BlgCronManager($module);
        $cron->runCheck();
        Configuration::updateValue(BypassLaLigaGate::CONF_LAST_TICK, time());

        $state = $module->getState();
        $cfg   = $module->getConfig();
        $diag  = $module->getCronDiagnostics();

        $msg = '';
        if (!empty($state['manual_override'])) {
            $msg = 'Forzado manual activo. ';
        }
        if ($state['last_status'] === 'SI') {
            $msg .= 'Hay bloqueos activos ahora mismo.';
        } else {
            $msg .= 'No hay bloqueos activos.';
            if (!empty($state['bypass_active']) && empty($state['manual_override']) && (int) $state['blocks_ended_at'] > 0) {
                $remaining = max(0, ((int) $cfg[BypassLaLigaGate::CONF_COOLDOWN] * 60) - (time() - (int) $state['blocks_ended_at']));
                if ($remaining > 0) {
                    $msg .= ' Periodo de espera: ' . (int) ceil($remaining / 60) . ' min.';
                }
            }
        }
        if (!empty($state['last_source'])) {
            $msg .= ' (fuente: ' . $state['last_source'] . ')';
        }

        $isBypass = !empty($state['bypass_active']) || !empty($state['manual_override']);
        $html     = $module->renderDnsTable($module->getDnsCache(), $cfg[BypassLaLigaGate::CONF_SELECTED]);

        $this->blgSendSuccess([
            'message'    => $msg,
            'blocked'    => $state['last_status'],
            'bypass'     => $isBypass ? 'SI' : 'NO',
            'lastCheck'  => $state['last_check'],
            'lastSource' => $state['last_source'],
            'nextCheck'  => $diag['next_human'],
            'html'       => $html,
        ]);
    }

    public function ajaxProcessForceProxyOff()
    {
        $this->blgDoApply(false);
    }

    public function ajaxProcessForceProxyOn()
    {
        $this->blgDoApply(true);
    }

    private function blgDoApply($proxiedOn)
    {
        /** @var BypassLaLigaGate $module */
        $module = $this->blgGetModule();
        $cron   = new BlgCronManager($module);
        $res    = $cron->applyProxy($proxiedOn);

        if (empty($res['ok'])) {
            $this->blgSendError($res['message']);
        }

        $cfg  = $module->getConfig();
        $html = $module->renderDnsTable($module->getDnsCache(), $cfg[BypassLaLigaGate::CONF_SELECTED]);

        $this->blgSendSuccess([
            'message' => $res['message'],
            'html'    => $html,
            'bypass'  => $res['bypass'],
        ]);
    }

    private function blgGetConfigWithFormCreds()
    {
        /** @var BypassLaLigaGate $module */
        $module = $this->blgGetModule();
        $cfg    = $module->getConfig();

        $out = [
            'auth_type'  => $cfg[BypassLaLigaGate::CONF_AUTH_TYPE],
            'cf_email'   => $cfg[BypassLaLigaGate::CONF_CF_EMAIL],
            'cf_api_key' => $cfg[BypassLaLigaGate::CONF_CF_API_KEY],
            'cf_zone_id' => $cfg[BypassLaLigaGate::CONF_CF_ZONE_ID],
        ];

        if (Tools::getValue('cf_api_key')) {
            $out['auth_type']  = (string) Tools::getValue('auth_type', $out['auth_type']);
            $out['cf_email']   = (string) Tools::getValue('cf_email', $out['cf_email']);
            $out['cf_api_key'] = (string) Tools::getValue('cf_api_key', $out['cf_api_key']);
            $out['cf_zone_id'] = (string) Tools::getValue('cf_zone_id', $out['cf_zone_id']);
        }

        return $out;
    }

    private function blgGetModule()
    {
        if (!$this->module) {
            $this->module = Module::getInstanceByName('bypasslaligagate');
        }
        return $this->module;
    }

    /**
     * Envía respuesta JSON OK y termina la ejecución.
     * Renombrado con prefijo blg* para evitar colisión con
     * AdminControllerCore::jsonSuccess() (PHP no permite reducir
     * la visibilidad public→private al heredar).
     */
    private function blgSendSuccess(array $data)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => true,
            'data'    => $data,
        ]);
        exit;
    }

    private function blgSendError($message)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'data'    => ['message' => (string) $message],
        ]);
        exit;
    }
}

<?php
/**
 * Endpoint público para cron externo del servidor.
 *
 * Acceso: index.php?fc=module&module=bypasslaligagate&controller=cron&token=XXXX
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BypassLaLigaGateCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl  = true;

    public function initContent()
    {
        $this->ajax = true;

        $providedToken = (string) Tools::getValue('token');
        $expected      = (string) Configuration::get(BypassLaLigaGate::CONF_CRON_SECRET);

        if ($expected === '' || !hash_equals($expected, $providedToken)) {
            header('HTTP/1.1 403 Forbidden');
            echo 'Token no válido';
            exit;
        }

        try {
            $cron = new BlgCronManager($this->module);
            $cron->runCheck();
            Configuration::updateValue(BypassLaLigaGate::CONF_LAST_TICK, time());

            header('Content-Type: text/plain; charset=utf-8');
            echo "Bypass LaLigaGate: cron OK\n";
            exit;
        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Error: ' . $e->getMessage();
            exit;
        }
    }

    public function display()
    {
        // Nunca alcanza display() — initContent() ya hace exit.
    }
}

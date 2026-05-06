<?php
/**
 * Notificador por email para cambios de estado del proxy.
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlgEmailNotifier
{
    /** @var BypassLaLigaGate */
    private $module;

    public function __construct(BypassLaLigaGate $module)
    {
        $this->module = $module;
    }

    private function getRecipient()
    {
        $email = (string) Configuration::get(BypassLaLigaGate::CONF_NOTIF_EMAIL);
        if ($email === '' || !Validate::isEmail($email)) {
            $email = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        return $email;
    }

    private function send($template, array $vars, $subject)
    {
        $to    = $this->getRecipient();
        if (!$to) {
            return false;
        }
        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $idShop = (int) Context::getContext()->shop->id;

        $mailDir = _PS_MODULE_DIR_ . $this->module->name . '/mails/';

        return Mail::Send(
            $idLang,
            $template,
            $subject,
            $vars,
            $to,
            null,
            null,
            null,
            null,
            null,
            $mailDir,
            false,
            $idShop
        );
    }

    public function notifyProxyDisabled()
    {
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $shopUrl  = (Tools::usingSecureMode() ? 'https://' : 'http://') . Tools::getHttpHost() . __PS_BASE_URI__;

        return $this->send(
            'proxy_disabled',
            [
                '{shop_name}'  => $shopName,
                '{shop_url}'   => $shopUrl,
                '{version}'    => $this->module->version,
                '{admin_link}' => $this->getAdminLink(),
            ],
            '[' . $shopName . '] Proxy de Cloudflare desactivado por bloqueos de La Liga'
        );
    }

    public function notifyProxyRestored()
    {
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $shopUrl  = (Tools::usingSecureMode() ? 'https://' : 'http://') . Tools::getHttpHost() . __PS_BASE_URI__;

        return $this->send(
            'proxy_restored',
            [
                '{shop_name}'  => $shopName,
                '{shop_url}'   => $shopUrl,
                '{version}'    => $this->module->version,
                '{admin_link}' => $this->getAdminLink(),
            ],
            '[' . $shopName . '] Proxy de Cloudflare reactivado'
        );
    }

    public function notifySummary($freq, $periodStart, $periodEnd, array $episodes, array $stats)
    {
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $shopUrl  = (Tools::usingSecureMode() ? 'https://' : 'http://') . Tools::getHttpHost() . __PS_BASE_URI__;
        $label    = ($freq === 'daily') ? 'diario' : 'semanal';
        $count    = (int) ($stats['count'] ?? 0);

        $fmt      = 'Y-m-d H:i';
        $fromStr  = date($fmt, (int) $periodStart);
        $toStr    = date($fmt, (int) $periodEnd);

        $detailLines = [];
        if ($count === 0) {
            $detailText = 'Durante este periodo no se han detectado bloqueos. La web habría estado accesible sin necesidad del bypass.';
        } else {
            $totalStr   = $this->formatDuration((int) ($stats['total_seconds'] ?? 0));
            $longestStr = $this->formatDuration((int) ($stats['longest'] ?? 0));
            $detailText  = "Bloqueos detectados: {$count}\n";
            $detailText .= "Tiempo total que la web habría estado inaccesible sin el bypass: {$totalStr}\n";
            $detailText .= "Bloqueo más largo: {$longestStr}\n\n";
            $detailText .= "Detalle:\n";
            $shown = array_slice($episodes, 0, 20);
            foreach ($shown as $ep) {
                $startStr = date($fmt, (int) $ep['start']);
                $durStr   = $this->formatDuration((int) $ep['duration']);
                $isps     = (int) ($ep['isps_max'] ?? 0);
                $line     = "- {$startStr} ({$durStr})";
                if ($isps > 0) {
                    $line .= " | ISPs afectados: {$isps}";
                }
                $detailText .= $line . "\n";
                $detailLines[] = $line;
            }
            $extra = $count - count($shown);
            if ($extra > 0) {
                $detailText .= "...y {$extra} más.\n";
            }
        }

        return $this->send(
            'summary',
            [
                '{shop_name}'    => $shopName,
                '{shop_url}'     => $shopUrl,
                '{version}'      => $this->module->version,
                '{label}'        => $label,
                '{count}'        => (string) $count,
                '{period_from}'  => $fromStr,
                '{period_to}'    => $toStr,
                '{detail_text}'  => $detailText,
                '{detail_html}'  => nl2br(htmlspecialchars($detailText, ENT_QUOTES, 'UTF-8')),
                '{admin_link}'   => $this->getAdminLink(),
            ],
            '[' . $shopName . '] Resumen ' . $label . ' - ' . $count . ' bloqueo(s) evitado(s)'
        );
    }

    private function getAdminLink()
    {
        $url = (Tools::usingSecureMode() ? 'https://' : 'http://') . Tools::getHttpHost() . __PS_BASE_URI__;
        return $url;
    }

    public function formatDuration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds < 60) {
            return $seconds . 's';
        }
        $hours = intdiv($seconds, 3600);
        $mins  = intdiv($seconds % 3600, 60);
        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $mins);
        }
        return $mins . 'm';
    }
}

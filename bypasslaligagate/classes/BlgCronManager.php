<?php
/**
 * Gestor de cron / chequeo periódico.
 *
 * Encapsula la lógica de comprobación de bloqueos, decisión de proxy ON/OFF
 * con cooldown, registro de episodios y notificación por email.
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlgCronManager
{
    /** @var BypassLaLigaGate */
    private $module;

    public function __construct(BypassLaLigaGate $module)
    {
        $this->module = $module;
    }

    /**
     * Comprobación principal. Notifica por email si hay cambio de estado.
     */
    public function runCheck()
    {
        $cfg   = $this->module->getConfig();
        $state = $this->module->getState();
        $api   = new BlgCloudflareApi([
            'auth_type'  => $cfg[BypassLaLigaGate::CONF_AUTH_TYPE],
            'cf_email'   => $cfg[BypassLaLigaGate::CONF_CF_EMAIL],
            'cf_api_key' => $cfg[BypassLaLigaGate::CONF_CF_API_KEY],
            'cf_zone_id' => $cfg[BypassLaLigaGate::CONF_CF_ZONE_ID],
        ]);

        if (!$api->isConfigured() || empty($cfg[BypassLaLigaGate::CONF_SELECTED])) {
            return;
        }

        $prevStatus = isset($state['last_status']) ? (string) $state['last_status'] : 'NO';

        $checker = new BlgBlockChecker();
        $status  = $checker->checkStatus((int) $cfg[BypassLaLigaGate::CONF_MIN_ISPS]);

        $now = time();
        $state['last_check']    = date('Y-m-d H:i:s', $now);
        $state['last_check_ts'] = $now;
        $state['last_source']   = isset($status['source']) ? (string) $status['source'] : '';

        if (!empty($status['error'])) {
            $this->module->saveState($state);
            return;
        }

        $blocksActive = !empty($status['blocked']);
        $state['last_status'] = $blocksActive ? 'SI' : 'NO';

        $this->recordBlockTransition($prevStatus, $blocksActive, (int) ($status['blocked_isps'] ?? 0), $now);

        if (!empty($state['manual_override'])) {
            $this->module->saveState($state);
            return;
        }

        $wasActive     = !empty($state['bypass_active']);
        $blocksEndedAt = (int) $state['blocks_ended_at'];
        $cooldownSecs  = max(5, (int) $cfg[BypassLaLigaGate::CONF_COOLDOWN]) * 60;

        $shouldDisable = false;
        if ($blocksActive) {
            $shouldDisable = true;
            $state['blocks_ended_at'] = 0;
        } elseif ($wasActive) {
            if ($blocksEndedAt <= 0) {
                $state['blocks_ended_at'] = $now;
                $blocksEndedAt            = $now;
                $shouldDisable            = true;
            } elseif (($now - $blocksEndedAt) < $cooldownSecs) {
                $shouldDisable = true;
            }
        }

        $desiredProxy = !$shouldDisable;

        $fresh   = $api->fetchDnsRecords();
        $changed = false;
        foreach ((array) $cfg[BypassLaLigaGate::CONF_SELECTED] as $rid) {
            $rec = BypassLaLigaGate::findRecord($fresh, $rid);
            if (!$rec) {
                continue;
            }
            $result = $api->setProxyStatus($rec, $desiredProxy);
            if (!empty($result['success']) && empty($result['skipped'])) {
                $changed = true;
            }
        }

        if ($changed) {
            $updated = $api->fetchDnsRecords();
            if (!empty($updated)) {
                $fresh = $updated;
            }
        }

        if (!empty($fresh)) {
            $this->module->saveDnsCache($fresh);
        }

        $stateChangedToActive   = false;
        $stateChangedToInactive = false;

        if ($shouldDisable) {
            if (!$wasActive) {
                $state['bypass_since']   = $now;
                $stateChangedToActive    = true;
            }
            $state['bypass_active'] = 1;
        } else {
            if ($wasActive) {
                $stateChangedToInactive = true;
            }
            $state['bypass_active']   = 0;
            $state['bypass_since']    = 0;
            $state['blocks_ended_at'] = 0;
        }

        $this->module->saveState($state);

        if (!empty($cfg[BypassLaLigaGate::CONF_EMAIL_NOTIF])) {
            $notifier = new BlgEmailNotifier($this->module);
            if ($stateChangedToActive) {
                $notifier->notifyProxyDisabled();
            } elseif ($stateChangedToInactive) {
                $notifier->notifyProxyRestored();
            }
        }

        $this->maybeRunSummary();
    }

    /**
     * Registra transiciones de bloqueo en el log de episodios.
     */
    private function recordBlockTransition($prevStatus, $blocksActive, $blockedIsps, $now)
    {
        $wasBlocked = ($prevStatus === 'SI');

        if (!$wasBlocked && $blocksActive) {
            $this->module->appendBlockEpisode([
                'start'    => $now,
                'end'      => 0,
                'isps_max' => $blockedIsps,
            ]);
            return;
        }

        if ($wasBlocked && !$blocksActive) {
            $log = $this->module->getBlockLog();
            for ($i = count($log) - 1; $i >= 0; --$i) {
                if (empty($log[$i]['end'])) {
                    $log[$i]['end'] = $now;
                    $this->module->saveBlockLog($log);
                    return;
                }
            }
            $this->module->appendBlockEpisode([
                'start'    => $now - 60,
                'end'      => $now,
                'isps_max' => $blockedIsps,
            ]);
            return;
        }

        if ($wasBlocked && $blocksActive && $blockedIsps > 0) {
            $log = $this->module->getBlockLog();
            for ($i = count($log) - 1; $i >= 0; --$i) {
                if (empty($log[$i]['end'])) {
                    if ($blockedIsps > (int) ($log[$i]['isps_max'] ?? 0)) {
                        $log[$i]['isps_max'] = $blockedIsps;
                        $this->module->saveBlockLog($log);
                    }
                    return;
                }
            }
        }
    }

    /**
     * Envía el resumen periódico si toca según frecuencia y hora configurada.
     */
    private function maybeRunSummary()
    {
        $cfg = $this->module->getConfig();
        if (empty($cfg[BypassLaLigaGate::CONF_SUMMARY_ENABLED])) {
            return;
        }

        $freq = ($cfg[BypassLaLigaGate::CONF_SUMMARY_FREQ] === 'daily') ? 'daily' : 'weekly';
        $now  = time();

        $lastSent = (int) Configuration::get(BypassLaLigaGate::CONF_SUMMARY_LAST_TS);
        $minGap   = ($freq === 'daily') ? 12 * 3600 : 3 * 86400;
        if ($lastSent > 0 && ($now - $lastSent) < $minGap) {
            return;
        }

        $time = (string) $cfg[BypassLaLigaGate::CONF_SUMMARY_TIME];
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            $m = [null, '10', '00'];
        }
        $targetHour   = (int) $m[1];
        $targetMinute = (int) $m[2];
        $hour         = (int) date('H', $now);
        $minute       = (int) date('i', $now);

        $minutesNow    = $hour * 60 + $minute;
        $minutesTarget = $targetHour * 60 + $targetMinute;
        $tolerance     = 60;
        if (abs($minutesNow - $minutesTarget) > $tolerance) {
            return;
        }

        if ($freq === 'weekly') {
            if ((int) date('N', $now) !== 1) {
                return;
            }
        }

        $periodEnd   = $now;
        $periodStart = $periodEnd - ($freq === 'daily' ? 86400 : 7 * 86400);

        $log      = $this->module->getBlockLog();
        $episodes = [];
        $longest  = 0;
        $total    = 0;

        foreach ($log as $ep) {
            $start = (int) ($ep['start'] ?? 0);
            $end   = (int) ($ep['end'] ?? 0);
            if ($start <= 0) {
                continue;
            }
            if ($end <= 0) {
                $end = $now;
            }
            if ($end <= $periodStart || $start >= $periodEnd) {
                continue;
            }
            $clipStart = max($start, $periodStart);
            $clipEnd   = min($end, $periodEnd);
            $dur       = max(0, $clipEnd - $clipStart);
            $total    += $dur;
            if ($dur > $longest) {
                $longest = $dur;
            }
            $episodes[] = [
                'start'    => $clipStart,
                'end'      => $clipEnd,
                'duration' => $dur,
                'isps_max' => (int) ($ep['isps_max'] ?? 0),
            ];
        }

        $stats = [
            'count'         => count($episodes),
            'total_seconds' => $total,
            'longest'       => $longest,
        ];

        $notifier = new BlgEmailNotifier($this->module);
        $notifier->notifySummary($freq, $periodStart, $periodEnd, $episodes, $stats);

        Configuration::updateValue(BypassLaLigaGate::CONF_SUMMARY_LAST_TS, $periodEnd);
    }

    /**
     * Aplica el proxy ON/OFF a los registros seleccionados (acción manual).
     * Devuelve resumen para mostrar al usuario.
     */
    public function applyProxy($proxiedOn)
    {
        $cfg = $this->module->getConfig();
        $api = new BlgCloudflareApi([
            'auth_type'  => $cfg[BypassLaLigaGate::CONF_AUTH_TYPE],
            'cf_email'   => $cfg[BypassLaLigaGate::CONF_CF_EMAIL],
            'cf_api_key' => $cfg[BypassLaLigaGate::CONF_CF_API_KEY],
            'cf_zone_id' => $cfg[BypassLaLigaGate::CONF_CF_ZONE_ID],
        ]);

        if (!$api->isConfigured()) {
            return ['ok' => false, 'message' => 'Cloudflare no está configurado. Guarda las credenciales primero.'];
        }
        if (empty($cfg[BypassLaLigaGate::CONF_SELECTED])) {
            return ['ok' => false, 'message' => 'No hay registros DNS seleccionados. Marca los registros y guarda los ajustes.'];
        }

        $fresh = $api->fetchDnsRecords();
        if (empty($fresh)) {
            return ['ok' => false, 'message' => 'No se pudieron obtener los registros DNS de Cloudflare.'];
        }

        $map = [];
        foreach ($fresh as $rec) {
            if (!empty($rec['id'])) {
                $map[$rec['id']] = $rec;
            }
        }

        $ok    = 0;
        $skip  = 0;
        $errors = [];
        foreach ((array) $cfg[BypassLaLigaGate::CONF_SELECTED] as $rid) {
            if (!isset($map[$rid])) {
                $errors[] = substr((string) $rid, 0, 10) . '...: No encontrado';
                continue;
            }
            $result = $api->setProxyStatus($map[$rid], $proxiedOn);
            if (!empty($result['success'])) {
                if (empty($result['skipped'])) {
                    ++$ok;
                } else {
                    ++$skip;
                }
            } else {
                $errors[] = ($result['record_name'] ?? $rid) . ': ' . ($result['error'] ?? '?');
            }
        }

        $updated = $api->fetchDnsRecords();
        if (!empty($updated)) {
            $this->module->saveDnsCache($updated);
        }

        $state = $this->module->getState();
        if ($proxiedOn) {
            $state['bypass_active']   = 0;
            $state['bypass_since']    = 0;
            $state['manual_override'] = 0;
        } else {
            $state['bypass_active']   = 1;
            $state['bypass_since']    = time();
            $state['manual_override'] = 1;
        }
        $this->module->saveState($state);

        $label = $proxiedOn ? 'ON' : 'OFF';
        $msg   = "Proxy {$label} aplicado a {$ok} registros.";
        if ($skip > 0) {
            $msg .= " {$skip} ya estaban en {$label}.";
        }
        if (!empty($errors)) {
            $msg .= ' Errores: ' . implode(' | ', $errors);
        }

        return [
            'ok'      => true,
            'message' => $msg,
            'bypass'  => $proxiedOn ? 'NO' : 'SI',
        ];
    }
}

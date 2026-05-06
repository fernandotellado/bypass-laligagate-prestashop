<?php
/**
 * Comprobador de bloqueos en hayahora.futbol.
 *
 * Estrategia:
 *  - min_isps == 1 → TXT primero (~150 B), fallback a JSON.
 *  - min_isps  > 1 → JSON (TXT no tiene detalle por ISP).
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlgBlockChecker
{
    /** @var string */
    private $jsonUrl = 'https://hayahora.futbol/estado/data.json';

    /** @var string */
    private $txtUrl  = 'https://hayahora.futbol/estado/blocked-any.txt';

    public function checkStatus($minIsps = 1)
    {
        $minIsps = max(1, (int) $minIsps);
        $source  = ($minIsps === 1) ? 'txt' : 'json';

        if ($source === 'txt') {
            $result = $this->checkViaTxt();
            if ($result['error'] === '') {
                return $result;
            }
            $result = $this->checkViaJson($minIsps);
            $result['source'] = $result['source'] . '+txt-fallback';
            return $result;
        }

        return $this->checkViaJson($minIsps);
    }

    private function httpGet($url, $timeout = 10)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BypassLaLigaGate/PrestaShop ' . _PS_VERSION_);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $resp     = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return [0, '', '', $err ?: 'Error desconocido'];
        }

        $headers = substr($resp, 0, $headSize);
        $body    = substr($resp, $headSize);
        return [$code, (string) $body, (string) $headers, ''];
    }

    private function checkViaTxt()
    {
        $result = [
            'blocked'      => false,
            'last_update'  => '',
            'error'        => '',
            'blocked_isps' => 0,
            'source'       => 'txt',
        ];

        list($code, $body, $headers, $err) = $this->httpGet($this->txtUrl, 10);
        if ($code === 0) {
            $result['error'] = $err;
            return $result;
        }
        if ($code !== 200) {
            $result['error'] = 'HTTP ' . $code;
            return $result;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim((string) $body));
        $ips = [];
        if (is_array($lines)) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $ips[] = $line;
                }
            }
        }

        $result['blocked']      = (count($ips) > 0);
        $result['blocked_isps'] = count($ips);

        if (preg_match('/^Last-Modified:\s*(.+)$/im', (string) $headers, $m)) {
            $ts = strtotime(trim($m[1]));
            if ($ts !== false) {
                $result['last_update'] = gmdate('c', $ts);
            }
        }

        return $result;
    }

    private function checkViaJson($minIsps)
    {
        $result = [
            'blocked'      => false,
            'last_update'  => '',
            'error'        => '',
            'blocked_isps' => 0,
            'source'       => 'json',
        ];

        list($code, $body, , $err) = $this->httpGet($this->jsonUrl, 20);
        if ($code === 0) {
            $result['error'] = $err;
            return $result;
        }
        if ($code !== 200) {
            $result['error'] = 'HTTP ' . $code;
            return $result;
        }

        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            $result['error'] = 'Respuesta JSON no válida';
            return $result;
        }

        if (!empty($json['lastUpdate']) && is_string($json['lastUpdate'])) {
            $result['last_update'] = $json['lastUpdate'];
        }

        $blockedIsps = $this->countBlockedIsps($json);
        $result['blocked_isps'] = $blockedIsps;
        $result['blocked']      = ($blockedIsps >= max(1, $minIsps));

        return $result;
    }

    private function countBlockedIsps(array $json)
    {
        $blockedByIsp = [];
        $ipsData = null;
        foreach (['ips', 'ip', 'data', 'results'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $ipsData = $json[$key];
                break;
            }
        }

        if (!is_array($ipsData)) {
            $ipMap  = $this->extractIpMap($json);
            foreach ($ipMap as $blocked) {
                if ($blocked === true) {
                    return 1;
                }
            }
            return 0;
        }

        foreach ($ipsData as $entry) {
            if (!is_array($entry) || empty($entry['ip'])) {
                continue;
            }
            $isp     = !empty($entry['isp']) ? strtolower(trim((string) $entry['isp'])) : 'unknown';
            $blocked = $this->extractBlockedFromObject($entry);
            if ($blocked === true) {
                $blockedByIsp[$isp] = true;
            }
        }

        return count($blockedByIsp);
    }

    private function extractIpMap(array $json)
    {
        $map = [];
        $ipsData = null;
        foreach (['ips', 'ip', 'data', 'results'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) {
                $ipsData = $json[$key];
                break;
            }
        }

        if ($ipsData === null) {
            $allIps = !empty($json);
            foreach ($json as $k => $v) {
                if (!filter_var($k, FILTER_VALIDATE_IP)) {
                    $allIps = false;
                    break;
                }
            }
            if ($allIps) {
                $ipsData = $json;
            }
        }

        if (!is_array($ipsData)) {
            return $map;
        }

        foreach ($ipsData as $ip => $value) {
            if (is_int($ip) && is_array($value) && !empty($value['ip'])) {
                $realIp  = (string) $value['ip'];
                $blocked = $this->extractBlockedFromObject($value);
                if ($blocked !== null && (!isset($map[$realIp]) || $blocked === true)) {
                    $map[$realIp] = $blocked;
                }
                continue;
            }
            if (!is_string($ip)) {
                continue;
            }
            if (is_bool($value)) {
                $map[$ip] = $value;
                continue;
            }
            if (is_int($value)) {
                $map[$ip] = ($value !== 0);
                continue;
            }
            if (is_string($value)) {
                $n = $this->normalizeBoolString($value);
                if ($n !== null) {
                    $map[$ip] = $n;
                }
                continue;
            }
            if (is_array($value)) {
                $b = $this->extractBlockedFromObject($value);
                if ($b !== null) {
                    $map[$ip] = $b;
                }
            }
        }

        return $map;
    }

    private function normalizeBoolString($value)
    {
        $lower = strtolower(trim((string) $value));
        if (in_array($lower, ['1', 'true', 'yes', 'si', 'blocked', 'on'], true)) {
            return true;
        }
        if (in_array($lower, ['0', 'false', 'no', 'unblocked', 'off'], true)) {
            return false;
        }
        return null;
    }

    private function extractBlockedFromObject(array $obj)
    {
        foreach (['blocked', 'Blocked', 'BLOCKED'] as $key) {
            if (isset($obj[$key])) {
                if (is_bool($obj[$key])) {
                    return $obj[$key];
                }
                $n = $this->normalizeBoolString((string) $obj[$key]);
                if ($n !== null) {
                    return $n;
                }
            }
        }
        foreach (['stateChanges', 'statechanges', 'StateChanges'] as $key) {
            if (isset($obj[$key]) && is_array($obj[$key])) {
                return $this->latestStateFromChanges($obj[$key]);
            }
        }
        return null;
    }

    private function latestStateFromChanges(array $changes)
    {
        $maxTs = null;
        $state = null;

        foreach ($changes as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $ts = null;
            if (isset($entry['timestamp'])) {
                $ts = strtotime((string) $entry['timestamp']);
            } elseif (isset($entry['date'])) {
                $ts = strtotime((string) $entry['date']);
            }
            if ($ts === null || $ts === false) {
                continue;
            }
            if ($maxTs === null || $ts > $maxTs) {
                $maxTs = $ts;
                foreach (['blocked', 'state', 'status'] as $key) {
                    if (isset($entry[$key])) {
                        if (is_bool($entry[$key])) {
                            $state = $entry[$key];
                        } else {
                            $n = $this->normalizeBoolString((string) $entry[$key]);
                            if ($n !== null) {
                                $state = $n;
                            }
                        }
                        break;
                    }
                }
            }
        }

        $maxStaleSeconds = 6 * 3600;
        if ($state === true && $maxTs !== null && (time() - $maxTs) > $maxStaleSeconds) {
            $state = false;
        }
        return $state;
    }
}

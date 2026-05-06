<?php
/**
 * Wrapper de la API de Cloudflare.
 *
 * @author    Mantenimiento WordPress <fernando@tellado.es>
 * @copyright 2026 Mantenimiento WordPress
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL v2 or later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class BlgCloudflareApi
{
    /** @var array */
    private $settings;

    /** @var string */
    private $apiBase = 'https://api.cloudflare.com/client/v4';

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    private function getHeaders()
    {
        $headers = ['Content-Type: application/json'];

        if (($this->settings['auth_type'] ?? 'global') === 'token') {
            $headers[] = 'Authorization: Bearer ' . $this->settings['cf_api_key'];
        } else {
            $headers[] = 'X-Auth-Email: ' . $this->settings['cf_email'];
            $headers[] = 'X-Auth-Key: ' . $this->settings['cf_api_key'];
        }

        return $headers;
    }

    public function isConfigured()
    {
        if (empty($this->settings['cf_api_key']) || empty($this->settings['cf_zone_id'])) {
            return false;
        }
        if (($this->settings['auth_type'] ?? 'global') === 'global' && empty($this->settings['cf_email'])) {
            return false;
        }
        return true;
    }

    /**
     * Petición HTTP usando cURL. Devuelve [code, body] o [0, ''] en error.
     */
    private function request($method, $url, $body = null, $timeout = 20)
    {
        if (!function_exists('curl_init')) {
            return [0, '', 'cURL no disponible en el servidor'];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->getHeaders());
        curl_setopt($ch, CURLOPT_USERAGENT, 'BypassLaLigaGate/PrestaShop');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        } elseif ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            return [0, '', $err ?: 'Error desconocido'];
        }
        return [$code, (string) $resp, ''];
    }

    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Faltan credenciales de Cloudflare.',
            ];
        }

        $url = $this->apiBase . '/zones/' . rawurlencode($this->settings['cf_zone_id']);
        list($code, $body, $err) = $this->request('GET', $url);

        if ($code === 0) {
            return [
                'success' => false,
                'message' => 'Error de conexión: ' . $err,
            ];
        }

        $json = json_decode($body, true);
        if ($code !== 200 || empty($json['success'])) {
            $errMsg = isset($json['errors'][0]['message']) ? $json['errors'][0]['message'] : 'HTTP ' . $code;
            return [
                'success' => false,
                'message' => 'Error de Cloudflare: ' . $errMsg,
            ];
        }

        $zoneName = isset($json['result']['name']) ? (string) $json['result']['name'] : '';
        return [
            'success'   => true,
            'message'   => 'Conexión correcta. Zona: ' . $zoneName,
            'zone_name' => $zoneName,
        ];
    }

    public function fetchDnsRecords()
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $allowed = ['A', 'AAAA', 'CNAME'];
        $records = [];
        $page    = 1;
        $totalPages = 1;

        do {
            $url = $this->apiBase . '/zones/' . rawurlencode($this->settings['cf_zone_id'])
                . '/dns_records?per_page=100&page=' . $page;
            list($code, $body) = $this->request('GET', $url, null, 30);
            if ($code !== 200) {
                break;
            }
            $json = json_decode($body, true);
            if (!is_array($json) || empty($json['success']) || !isset($json['result'])) {
                break;
            }

            foreach ($json['result'] as $rec) {
                $type = strtoupper((string) ($rec['type'] ?? ''));
                if (!in_array($type, $allowed, true)) {
                    continue;
                }
                $records[] = [
                    'id'      => (string) ($rec['id'] ?? ''),
                    'name'    => (string) ($rec['name'] ?? ''),
                    'type'    => $type,
                    'content' => (string) ($rec['content'] ?? ''),
                    'proxied' => isset($rec['proxied']) ? (bool) $rec['proxied'] : null,
                    'ttl'     => (int) ($rec['ttl'] ?? 1),
                ];
            }

            if (isset($json['result_info']['total_count'], $json['result_info']['per_page'])) {
                $per = (int) $json['result_info']['per_page'];
                if ($per > 0) {
                    $totalPages = (int) ceil((int) $json['result_info']['total_count'] / $per);
                }
            }
            ++$page;
        } while ($page <= $totalPages && $page <= 20);

        return $records;
    }

    public function setProxyStatus(array $record, $proxiedOn)
    {
        $name = (string) ($record['name'] ?? '');

        if (isset($record['proxied']) && (bool) $record['proxied'] === (bool) $proxiedOn) {
            return [
                'success'     => true,
                'skipped'     => true,
                'record_name' => $name,
            ];
        }

        $type = strtoupper((string) ($record['type'] ?? ''));
        if (!in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            return [
                'success'     => false,
                'error'       => "Tipo {$type} no admite proxy",
                'record_name' => $name,
            ];
        }

        $ttl = (int) ($record['ttl'] ?? 1);
        if ($proxiedOn) {
            $ttl = 1;
        }

        $payload = json_encode([
            'type'    => $type,
            'name'    => $name,
            'content' => (string) ($record['content'] ?? ''),
            'ttl'     => $ttl,
            'proxied' => (bool) $proxiedOn,
        ]);

        $url = $this->apiBase . '/zones/' . rawurlencode($this->settings['cf_zone_id'])
            . '/dns_records/' . rawurlencode($record['id']);

        list($code, $body, $err) = $this->request('PUT', $url, $payload, 30);

        if ($code === 0) {
            return [
                'success'     => false,
                'error'       => $err,
                'record_name' => $name,
            ];
        }

        $json = json_decode($body, true);
        if ($code !== 200 || !is_array($json) || empty($json['success'])) {
            $errMsg = isset($json['errors'][0]['message']) ? $json['errors'][0]['message'] : 'HTTP ' . $code;
            return [
                'success'     => false,
                'error'       => $errMsg,
                'record_name' => $name,
            ];
        }

        return [
            'success'     => true,
            'skipped'     => false,
            'record_name' => $name,
        ];
    }
}

<?php

namespace App\Services\Concerns;

trait ResolvesApiEndpoints
{
    /**
     * Resolve cURL network options with DNS pinning and IPv4 preference to avoid
     * systemd-resolved (127.0.0.53) container DNS lookup stalls.
     *
     * @param string $url Target endpoint URL
     * @return array Array formatted for Http::withOptions()
     */
    protected function getCurlOptions(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return [];
        }

        static $ipCache = [
            'api.meta.ai'    => '157.240.8.18',
            'api.openai.com' => '162.159.140.245',
        ];

        $ip = $ipCache[$host] ?? null;
        if (!$ip && function_exists('dns_get_record')) {
            try {
                $records = @dns_get_record($host, DNS_A);
                if (!empty($records[0]['ip'])) {
                    $ip = $records[0]['ip'];
                    $ipCache[$host] = $ip;
                }
            } catch (\Throwable) {
                // Ignore resolution failure and fall back to system
            }
        }

        $options = [
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ];

        if ($ip) {
            $port = parse_url($url, PHP_URL_PORT) ?? (str_starts_with($url, 'https://') ? 443 : 80);
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$ip}"];
        }

        return ['curl' => $options];
    }
}

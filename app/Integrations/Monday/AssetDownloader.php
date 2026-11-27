<?php

namespace App\Integrations\Monday;

use SensitiveParameter;

/** Exact source-location transport only. Owning adapters authorize scope/grants before and after downloading. */
class AssetDownloader
{
    private array $hosts;

    public function __construct(?array $allowedHosts = null)
    {
        $hosts = $allowedHosts ?? config('services.monday.asset_hosts', ['files.monday.com']);
        if (! is_array($hosts) || ! array_is_list($hosts) || $hosts === [] || count($hosts) > 20) {
            throw new AssetDownloadFailed('source_host_unapproved');
        }
        foreach ($hosts as $host) {
            if (! is_string($host) || ! $this->hostname($host) || $host !== strtolower($host)) {
                throw new AssetDownloadFailed('source_host_unapproved');
            }
        }
        $this->hosts = array_values(array_unique($hosts));
    }

    public function download(#[SensitiveParameter] AssetLocation $asset, int $maximumBytes): string
    {
        $body = '';
        $accepted = false;
        try {
            if ($maximumBytes < 1 || $maximumBytes > 104857600 || $asset->size() > $maximumBytes) {
                throw new AssetDownloadFailed('source_asset_invalid');
            }
            if (! extension_loaded('curl')) {
                throw new AssetDownloadFailed('source_transport_unavailable');
            }
            $deadline = $this->monotonic() + 20;
            $parts = parse_url($asset->sourceUrl());
            $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower($parts['host']) : '';
            if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ! $this->hostname($host)
                || ! in_array($host, $this->hosts, true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
                || (isset($parts['port']) && $parts['port'] !== 443)) {
                throw new AssetDownloadFailed('source_host_unapproved');
            }
            $addresses = $this->resolve($host);
            if ($addresses === [] || count($addresses) > 64) {
                throw new AssetDownloadFailed('source_dns_unavailable');
            }
            foreach ($addresses as $address) {
                if (! is_string($address) || ! $this->publicAddress($address)) {
                    throw new AssetDownloadFailed('source_address_rejected');
                }
            }
            // Prefer IPv4 when both are available; the transfer remains pinned to exactly one checked address.
            usort($addresses, fn (string $a, string $b) => (str_contains($a, ':') <=> str_contains($b, ':')) ?: strcmp($a, $b));
            $address = $addresses[0];
            $remaining = $deadline - $this->monotonic();
            if ($remaining <= 0) {
                throw new AssetDownloadFailed('source_transfer_timeout');
            }
            $reason = 'source_transfer_failed';
            $headerBytes = 0;
            $limit = min($maximumBytes, $asset->size());
            $options = [
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED, CURLOPT_VERBOSE => false, CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'Accept-Encoding: identity'],
                CURLOPT_CONNECTTIMEOUT_MS => min(5000, (int) floor($remaining * 1000)), CURLOPT_TIMEOUT_MS => max(1, (int) floor($remaining * 1000)),
                CURLOPT_LOW_SPEED_LIMIT => 1, CURLOPT_LOW_SPEED_TIME => 2, CURLOPT_MAXFILESIZE_LARGE => $limit,
                CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)],
                CURLOPT_IPRESOLVE => str_contains($address, ':') ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4,
                CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true, CURLOPT_DNS_CACHE_TIMEOUT => 0,
                CURLOPT_RETURNTRANSFER => false, CURLOPT_UNRESTRICTED_AUTH => false, CURLOPT_NOPROGRESS => false,
                CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headerBytes, &$reason, $limit, $deadline): int {
                    $headerBytes += strlen($line);
                    if ($this->monotonic() >= $deadline) {
                        $reason = 'source_transfer_timeout';

                        return 0;
                    }
                    if ($headerBytes > 65536) {
                        return 0;
                    }
                    if (preg_match('/^content-length:\s*([0-9]+)\s*$/iD', trim($line), $match)
                        && (strlen(ltrim($match[1], '0')) > 10 || (int) $match[1] > $limit)) {
                        $reason = 'source_size_mismatch';

                        return 0;
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$reason, $limit, $deadline): int {
                    if ($this->monotonic() >= $deadline) {
                        $reason = 'source_transfer_timeout';

                        return 0;
                    }
                    if (strlen($body) + strlen($chunk) > $limit) {
                        $reason = 'source_size_mismatch';

                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
                CURLOPT_XFERINFOFUNCTION => function () use ($deadline, &$reason): int {
                    if ($this->monotonic() >= $deadline) {
                        $reason = 'source_transfer_timeout';

                        return 1;
                    }

                    return 0;
                },
            ];
            $result = $this->transfer($asset->sourceUrl(), $options);
            if ($this->monotonic() >= $deadline) {
                throw new AssetDownloadFailed('source_transfer_timeout');
            }
            if (! $result['ok'] || $result['status'] !== 200) {
                throw new AssetDownloadFailed($reason);
            }
            if (! is_string($result['address']) || inet_pton($result['address']) !== inet_pton($address)) {
                throw new AssetDownloadFailed('source_address_rejected');
            }
            if (strlen($body) !== $asset->size()) {
                throw new AssetDownloadFailed('source_size_mismatch');
            }
            $accepted = true;

            return $body;
        } catch (AssetDownloadFailed $error) {
            throw $error;
        } catch (\Throwable) {
            throw new AssetDownloadFailed('source_transfer_failed');
        } finally {
            if (! $accepted && $body !== '' && function_exists('sodium_memzero')) {
                sodium_memzero($body);
            }
        }
    }

    /** System resolver may block; elapsed resolution consumes the budget and an expired budget starts no file request. */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $ip = ($record['type'] ?? null) === 'A' ? ($record['ip'] ?? null) : (($record['type'] ?? null) === 'AAAA' ? ($record['ipv6'] ?? null) : null);
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }

    protected function transfer(#[SensitiveParameter] string $url, array $options): array
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new AssetDownloadFailed('source_transport_unavailable');
        }
        try {
            if (! curl_setopt($handle, CURLOPT_URL, $url) || ! curl_setopt_array($handle, $options)) {
                throw new AssetDownloadFailed('source_transport_unavailable');
            }
            $ok = curl_exec($handle) === true;

            return ['ok' => $ok, 'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'address' => curl_getinfo($handle, CURLINFO_PRIMARY_IP)];
        } finally {
            unset($handle);
        }
    }

    protected function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    private function hostname(string $host): bool
    {
        return strlen($host) <= 253 && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) === 1;
    }

    private function publicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($address);
        if (strlen($packed) === 16) {
            // Mapped and NAT64 destinations may encode a private IPv4 endpoint despite global IPv6 flags.
            foreach ([str_repeat("\0", 10)."\xff\xff", substr(inet_pton('64:ff9b::'), 0, 12), substr(inet_pton('64:ff9b:1::'), 0, 6)] as $prefix) {
                if (str_starts_with($packed, $prefix)) {
                    return false;
                }
            }
        }

        return true;
    }
}

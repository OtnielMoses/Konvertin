<?php

namespace App\Conversion;

/**
 * Mencegah pengambilan halaman dari jaringan internal server (NFR-SEC-6).
 */
class UrlGuard
{
    /**
     * Memeriksa bentuk alamat tanpa melakukan resolusi DNS.
     */
    public function hasValidShape(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicAddress($host);
        }

        // Nama host harus memiliki domain tingkat atas, misalnya contoh.ac.id.
        return str_contains($host, '.');
    }

    /**
     * Meresolusi host dan memastikan seluruh alamatnya publik.
     *
     * @return string|null Alamat IP publik pertama untuk dipakai mesin konversi, atau null bila ditolak.
     */
    public function resolvePublicAddress(string $url): ?string
    {
        if (! $this->hasValidShape($url)) {
            return null;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        $addresses = $this->lookup($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    /**
     * Benar bila host dapat diresolusi dan setidaknya satu alamatnya berada
     * di jaringan privat, loopback, atau rentang khusus.
     */
    public function pointsToInternalNetwork(string $url): bool
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return false;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->lookup($host);

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                return true;
            }
        }

        return false;
    }

    public function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return list<string>
     */
    protected function lookup(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        $records = @dns_get_record($host, DNS_AAAA) ?: [];

        foreach ($records as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}

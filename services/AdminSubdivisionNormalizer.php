<?php
/**
 * EU-wide administrative subdivision normalization (provider-first).
 * No city-specific rules; sub-city units come from geocoder address components.
 */
final class AdminSubdivisionNormalizer {

    /** @var list<array{key:string,type:string,level:string}> */
    private static function nominatimSubcityCandidates(): array {
        return [
            ['key' => 'city_district', 'type' => 'district', 'level' => 'city_district'],
            ['key' => 'borough', 'type' => 'borough', 'level' => 'borough'],
            ['key' => 'quarter', 'type' => 'neighbourhood', 'level' => 'quarter'],
            ['key' => 'suburb', 'type' => 'municipality_subdivision', 'level' => 'suburb'],
            ['key' => 'neighbourhood', 'type' => 'neighbourhood', 'level' => 'neighbourhood'],
            ['key' => 'subdistrict', 'type' => 'municipality_subdivision', 'level' => 'subdistrict'],
            ['key' => 'ward', 'type' => 'ward', 'level' => 'ward'],
        ];
    }

    public static function emptySchema(): array {
        return [
            'country' => '',
            'country_code' => '',
            'region' => '',
            'county' => '',
            'city' => '',
            'subcity_name' => '',
            'subcity_type' => '',
            'subcity_level' => '',
            'postcode' => '',
            'street' => '',
            'house_number' => '',
            'formatted_address' => '',
            'lat' => 0.0,
            'lng' => 0.0,
            'provider' => '',
            'confidence' => 0.0,
            'admin_confidence' => 0.0,
            'subcity_source' => 'none',
        ];
    }

    /**
     * @param array<string,mixed>|null $geo Nominatim reverse JSON (jsonv2)
     * @param float|null $lat
     * @param float|null $lng
     */
    public static function fromNominatim(?array $geo, ?float $lat = null, ?float $lng = null): array {
        $out = self::emptySchema();
        $out['provider'] = 'nominatim';
        if (!is_array($geo)) {
            return $out;
        }
        $out['formatted_address'] = isset($geo['display_name']) ? trim((string)$geo['display_name']) : '';
        $addr = $geo['address'] ?? [];
        if (!is_array($addr)) {
            $addr = [];
        }
        $out['country'] = self::str($addr['country'] ?? '');
        $cc = self::str($addr['country_code'] ?? '');
        $out['country_code'] = $cc !== '' ? strtoupper($cc) : '';
        $out['region'] = self::str($addr['state'] ?? ($addr['region'] ?? ''));
        $out['county'] = self::str($addr['county'] ?? ($addr['state_district'] ?? ''));
        $out['city'] = self::str(
            $addr['city'] ?? ($addr['town'] ?? ($addr['village'] ?? ($addr['municipality'] ?? '')))
        );
        $out['postcode'] = self::str($addr['postcode'] ?? '');
        $out['street'] = self::str($addr['road'] ?? ($addr['pedestrian'] ?? ($addr['path'] ?? '')));
        $out['house_number'] = self::str($addr['house_number'] ?? ($addr['house_name'] ?? ''));

        $subName = '';
        $subType = '';
        $subLevel = '';
        foreach (self::nominatimSubcityCandidates() as $c) {
            $k = $c['key'];
            if (!empty($addr[$k]) && is_string($addr[$k])) {
                $v = trim($addr[$k]);
                if ($v !== '') {
                    $subName = $v;
                    $subType = $c['type'];
                    $subLevel = $c['level'];
                    break;
                }
            }
        }
        if ($subName === '') {
            foreach (['district', 'city_block'] as $k) {
                if (!empty($addr[$k]) && is_string($addr[$k])) {
                    $v = trim($addr[$k]);
                    if ($v !== '') {
                        $subName = $v;
                        $subType = $k === 'district' ? 'district' : 'unknown_subcity_unit';
                        $subLevel = $k;
                        break;
                    }
                }
            }
        }
        $out['subcity_name'] = $subName;
        $out['subcity_type'] = $subType ?: '';
        $out['subcity_level'] = $subLevel ?: '';

        if ($lat !== null && $lng !== null) {
            $out['lat'] = $lat;
            $out['lng'] = $lng;
        } elseif (isset($geo['lat'], $geo['lon'])) {
            $out['lat'] = (float)$geo['lat'];
            $out['lng'] = (float)$geo['lon'];
        }

        $out['confidence'] = 0.55;
        $out['admin_confidence'] = $subName !== '' ? 0.55 : 0.0;
        $out['subcity_source'] = $subName !== '' ? 'provider' : 'none';

        return $out;
    }

    /**
     * Placeholder for TomTom, HERE, Google, Geoapify — map raw result → same schema.
     *
     * @param array<string,mixed> $raw Provider-specific JSON
     */
    public static function fromProvider(string $provider, array $raw): array {
        $p = strtolower(trim($provider));
        $out = self::emptySchema();
        $out['provider'] = $p;

        switch ($p) {
            case 'tomtom':
                return self::fromTomTom($raw);
            case 'here':
                return self::fromHere($raw);
            case 'google':
            case 'google_maps':
                return self::fromGoogle($raw);
            case 'geoapify':
                return self::fromGeoapify($raw);
            default:
                return $out;
        }
    }

    /** @param array<string,mixed> $raw */
    private static function fromTomTom(array $raw): array {
        $out = self::emptySchema();
        $out['provider'] = 'tomtom';
        $addr = $raw['addresses'][0] ?? $raw['address'] ?? $raw;
        if (!is_array($addr)) {
            return $out;
        }
        $sub = self::str($addr['municipalitySubdivision'] ?? ($addr['municipalitySubdivisionName'] ?? ''));
        $out['country'] = self::str($addr['country'] ?? '');
        $out['country_code'] = strtoupper(self::str($addr['countryCode'] ?? ($addr['countrySubdivisionCode'] ?? '')));
        if (strlen($out['country_code']) > 2) {
            $out['country_code'] = '';
        }
        $out['region'] = self::str($addr['countrySubdivision'] ?? '');
        $out['city'] = self::str($addr['municipality'] ?? ($addr['localName'] ?? ''));
        $out['postcode'] = self::str($addr['postalCode'] ?? '');
        $out['street'] = self::str($addr['streetName'] ?? '');
        $out['house_number'] = self::str($addr['streetNumber'] ?? '');
        $out['formatted_address'] = self::str($addr['freeformAddress'] ?? '');
        if ($sub !== '') {
            $out['subcity_name'] = $sub;
            $out['subcity_type'] = 'municipality_subdivision';
            $out['subcity_level'] = 'municipalitySubdivision';
            $out['admin_confidence'] = 0.65;
            $out['subcity_source'] = 'provider';
        }
        $out['confidence'] = 0.6;
        return $out;
    }

    /** @param array<string,mixed> $raw */
    private static function fromHere(array $raw): array {
        $out = self::emptySchema();
        $out['provider'] = 'here';
        $items = $raw['items'][0] ?? $raw;
        if (!is_array($items)) {
            return $out;
        }
        $addr = $items['address'] ?? [];
        if (!is_array($addr)) {
            $addr = [];
        }
        $out['country'] = self::str($addr['countryName'] ?? '');
        $out['country_code'] = strtoupper(self::str($addr['countryCode'] ?? ''));
        $out['region'] = self::str($addr['state'] ?? '');
        $out['county'] = self::str($addr['county'] ?? '');
        $out['city'] = self::str($addr['city'] ?? ($addr['district'] ?? ''));
        $sub = self::str($addr['district'] ?? ($addr['subdistrict'] ?? ($addr['block'] ?? '')));
        if ($sub !== '' && $sub !== $out['city']) {
            $out['subcity_name'] = $sub;
            $out['subcity_type'] = 'district';
            $out['subcity_level'] = 'district';
            $out['admin_confidence'] = 0.6;
            $out['subcity_source'] = 'provider';
        }
        $out['postcode'] = self::str($addr['postalCode'] ?? '');
        $out['street'] = self::str($addr['street'] ?? ($addr['streetName'] ?? ''));
        $out['house_number'] = self::str($addr['houseNumber'] ?? '');
        $out['formatted_address'] = self::str($addr['label'] ?? '');
        $out['confidence'] = 0.58;
        return $out;
    }

    /** @param array<string,mixed> $raw Geocoding API result item */
    private static function fromGoogle(array $raw): array {
        $out = self::emptySchema();
        $out['provider'] = 'google';
        $results = $raw['results'][0] ?? $raw;
        if (!is_array($results)) {
            return $out;
        }
        $out['formatted_address'] = self::str($results['formatted_address'] ?? '');
        $ac = $results['address_components'] ?? [];
        if (!is_array($ac)) {
            return $out;
        }
        $typesMap = [
            'sublocality_level_1' => ['type' => 'sublocality', 'level' => 'sublocality_level_1'],
            'sublocality' => ['type' => 'sublocality', 'level' => 'sublocality'],
            'sublocality_level_2' => ['type' => 'sublocality', 'level' => 'sublocality_level_2'],
            'neighborhood' => ['type' => 'neighbourhood', 'level' => 'neighborhood'],
            'administrative_area_level_3' => ['type' => 'municipality_subdivision', 'level' => 'administrative_area_level_3'],
        ];
        foreach ($ac as $comp) {
            if (!is_array($comp)) {
                continue;
            }
            $types = $comp['types'] ?? [];
            if (!is_array($types)) {
                continue;
            }
            $long = self::str($comp['long_name'] ?? '');
            foreach ($typesMap as $t => $meta) {
                if (in_array($t, $types, true) && $long !== '') {
                    $out['subcity_name'] = $long;
                    $out['subcity_type'] = $meta['type'];
                    $out['subcity_level'] = $meta['level'];
                    $out['admin_confidence'] = 0.62;
                    $out['subcity_source'] = 'provider';
                    break 2;
                }
            }
        }
        foreach ($ac as $comp) {
            if (!is_array($comp)) {
                continue;
            }
            $types = $comp['types'] ?? [];
            if (!is_array($types)) {
                continue;
            }
            $long = self::str($comp['long_name'] ?? '');
            if (in_array('locality', $types, true) && $long !== '') {
                $out['city'] = $long;
            }
            if (in_array('country', $types, true) && $long !== '') {
                $out['country'] = $long;
            }
            if (in_array('country', $types, true)) {
                $short = self::str($comp['short_name'] ?? '');
                if (strlen($short) === 2) {
                    $out['country_code'] = strtoupper($short);
                }
            }
            if (in_array('administrative_area_level_1', $types, true) && $long !== '') {
                $out['region'] = $long;
            }
            if (in_array('postal_code', $types, true) && $long !== '') {
                $out['postcode'] = $long;
            }
            if (in_array('route', $types, true) && $long !== '') {
                $out['street'] = $long;
            }
            if (in_array('street_number', $types, true) && $long !== '') {
                $out['house_number'] = $long;
            }
        }
        $out['confidence'] = 0.6;
        return $out;
    }

    /** @param array<string,mixed> $raw */
    private static function fromGeoapify(array $raw): array {
        $out = self::emptySchema();
        $out['provider'] = 'geoapify';
        $props = $raw['properties'] ?? $raw;
        if (!is_array($props)) {
            return $out;
        }
        $out['country'] = self::str($props['country'] ?? '');
        $out['country_code'] = strtoupper(self::str($props['country_code'] ?? ''));
        $out['city'] = self::str($props['city'] ?? ($props['municipality'] ?? ''));
        $out['postcode'] = self::str($props['postcode'] ?? '');
        $out['street'] = self::str($props['street'] ?? ($props['road'] ?? ''));
        $out['house_number'] = self::str($props['housenumber'] ?? '');
        $out['formatted_address'] = self::str($props['formatted'] ?? ($props['address_line1'] ?? ''));
        $sub = self::str($props['suburb'] ?? ($props['district'] ?? ($props['quarter'] ?? '')));
        if ($sub !== '') {
            $out['subcity_name'] = $sub;
            $out['subcity_type'] = isset($props['district']) && $props['district'] === $sub ? 'district' : 'municipality_subdivision';
            $out['subcity_level'] = isset($props['district']) ? 'district' : 'suburb';
            $out['admin_confidence'] = 0.58;
            $out['subcity_source'] = 'provider';
        }
        $out['confidence'] = 0.57;
        return $out;
    }

    /**
     * Prefer commercial / primary geocoder fields when present (provider-first).
     *
     * @param array<string,mixed> $nominatimBase
     * @param array<string,mixed> $providerNorm from fromProvider()
     */
    public static function mergeProviderPreferred(array $nominatimBase, array $providerNorm): array {
        $keys = [
            'country', 'country_code', 'region', 'county', 'city',
            'subcity_name', 'subcity_type', 'subcity_level',
            'postcode', 'street', 'house_number', 'formatted_address',
        ];
        $out = $nominatimBase;
        foreach ($keys as $k) {
            $pv = $providerNorm[$k] ?? null;
            if (is_string($pv) && trim($pv) !== '') {
                $out[$k] = trim($pv);
            }
        }
        if (!empty($providerNorm['lat']) && is_numeric($providerNorm['lat'])) {
            $out['lat'] = (float)$providerNorm['lat'];
        }
        if (!empty($providerNorm['lng']) && is_numeric($providerNorm['lng'])) {
            $out['lng'] = (float)$providerNorm['lng'];
        }
        $pprov = self::str($providerNorm['provider'] ?? '');
        if ($pprov !== '') {
            $out['provider'] = substr($pprov, 0, 64);
        }
        $adm = (float)($providerNorm['admin_confidence'] ?? 0);
        $out['admin_confidence'] = max((float)($out['admin_confidence'] ?? 0), $adm);
        $conf = (float)($providerNorm['confidence'] ?? 0);
        if ($conf > 0) {
            $out['confidence'] = max((float)($out['confidence'] ?? 0), $conf);
        }
        $ss = self::str($providerNorm['subcity_source'] ?? '');
        if ($ss !== '') {
            $out['subcity_source'] = $ss;
        } elseif (self::str($providerNorm['subcity_name'] ?? '') !== '') {
            $out['subcity_source'] = 'provider';
        }
        return $out;
    }

    /**
     * Merge client-provided snapshot (same schema) over server when allowed.
     * Prefer non-empty fields with higher admin_confidence.
     *
     * @param array<string,mixed> $server
     * @param array<string,mixed>|null $client
     */
    public static function mergeClientSnapshot(array $server, ?array $client): array {
        if (!is_array($client) || !$client) {
            return $server;
        }
        $allowed = [
            'country', 'country_code', 'region', 'county', 'city',
            'subcity_name', 'subcity_type', 'subcity_level', 'postcode', 'street', 'house_number',
            'formatted_address', 'provider', 'confidence', 'admin_confidence', 'subcity_source',
        ];
        $sAdm = (float)($server['admin_confidence'] ?? 0);
        $cAdm = isset($client['admin_confidence']) && is_numeric($client['admin_confidence'])
            ? (float)$client['admin_confidence'] : 0;
        $useClientSubcity = ($cAdm > $sAdm + 0.05)
            || ($sAdm < 0.1 && self::str($client['subcity_name'] ?? '') !== '');

        foreach ($allowed as $k) {
            if (!array_key_exists($k, $client)) {
                continue;
            }
            $cv = $client[$k];
            if ($k === 'subcity_name' || $k === 'subcity_type' || $k === 'subcity_level' || $k === 'subcity_source') {
                if (!$useClientSubcity) {
                    continue;
                }
            }
            if (is_string($cv)) {
                $cv = trim($cv);
                if ($cv === '') {
                    continue;
                }
                if ($k === 'country_code') {
                    $cv = strtoupper(substr($cv, 0, 2));
                }
                $server[$k] = $k === 'formatted_address' ? substr($cv, 0, 512) : substr($cv, 0, 255);
            } elseif (is_numeric($cv) && in_array($k, ['confidence', 'admin_confidence'], true)) {
                $server[$k] = max(0.0, min(1.0, (float)$cv));
            }
        }
        if ($useClientSubcity && self::str($server['subcity_name'] ?? '') !== '') {
            $cp = self::str($client['provider'] ?? '');
            if ($cp !== '') {
                $server['provider'] = substr($cp, 0, 64);
            }
        }
        return $server;
    }

    /**
     * Mark subcity as boundary-verified (fallback path).
     *
     * @param array<string,mixed> $schema
     */
    public static function withBoundaryFallback(array $schema, string $name, string $type = 'unknown_subcity_unit'): array {
        if ($name === '') {
            return $schema;
        }
        $schema['subcity_name'] = $name;
        $schema['subcity_type'] = $type;
        $schema['subcity_level'] = 'boundary';
        $schema['subcity_source'] = 'fallback_boundary';
        $schema['admin_confidence'] = min((float)($schema['admin_confidence'] ?? 0), 0.45);
        return $schema;
    }

    private static function str($v): string {
        if ($v === null || !is_string($v)) {
            return '';
        }
        return trim($v);
    }
}

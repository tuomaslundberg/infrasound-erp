<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * Reads and validates a YAML inquiry file.
 * Returns a normalised array or throws InvalidArgumentException on bad input.
 */
class InquiryParser
{
    public function parse(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: $filePath");
        }

        try {
            $data = Yaml::parseFile($filePath);
        } catch (ParseException $e) {
            throw new InvalidArgumentException("YAML parse error: " . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid YAML: expected a mapping at the top level.");
        }

        $this->validateRequired($data);

        return $this->applyDefaults($data);
    }

    private function validateRequired(array $data): void
    {
        $required = [
            'meta.channel'                   => fn($d) => $d['meta']['channel'] ?? null,
            'customer.name'                  => fn($d) => $d['customer']['name'] ?? null,
            'customer.type'                  => fn($d) => $d['customer']['type'] ?? null,
            'gig.date'                       => fn($d) => $d['gig']['date'] ?? null,
            'gig.distances.from_turku_km'    => fn($d) => $d['gig']['distances']['from_turku_km'] ?? null,
            'gig.distances.car1_trip_km'     => fn($d) => $d['gig']['distances']['car1_trip_km'] ?? null,
        ];

        foreach ($required as $field => $accessor) {
            $value = $accessor($data);
            if ($value === null || $value === '') {
                throw new InvalidArgumentException("Missing required field: $field");
            }
        }

        $allowedChannels = ['mail', 'buukkaa_bandi'];
        if (!in_array($data['meta']['channel'], $allowedChannels, true)) {
            throw new InvalidArgumentException(
                "Invalid channel '{$data['meta']['channel']}'. Allowed: " . implode(', ', $allowedChannels)
            );
        }

        $allowedTypes = ['wedding', 'company', 'other'];
        if (!in_array($data['customer']['type'], $allowedTypes, true)) {
            throw new InvalidArgumentException(
                "Invalid customer type '{$data['customer']['type']}'. Allowed: " . implode(', ', $allowedTypes)
            );
        }

        $date = $data['gig']['date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)
            || !checkdate(
                (int) substr((string) $date, 5, 2),
                (int) substr((string) $date, 8, 2),
                (int) substr((string) $date, 0, 4)
            )
        ) {
            throw new InvalidArgumentException(
                "Invalid gig.date '$date'. Expected format: YYYY-MM-DD."
            );
        }
    }

    private function applyDefaults(array $data): array
    {
        $d = &$data['gig']['distances'];
        $d['car2_trip_km']           = (float) ($d['car2_trip_km'] ?? 0);
        $d['other_travel_costs_eur'] = (float) ($d['other_travel_costs_eur'] ?? 0);

        $o = &$data['order'];
        $o['sets']                   = (int)   ($o['sets'] ?? 3);
        $o['set_duration_min']       = (int)   ($o['set_duration_min'] ?? 45);
        $o['extras']                 = (string)($o['extras'] ?? '');
        $o['dynamic_pricing_tier1']  = (bool)  ($o['dynamic_pricing_tier1'] ?? false);
        $o['dynamic_pricing_tier2']  = (bool)  ($o['dynamic_pricing_tier2'] ?? false);
        // quoted_price_eur intentionally left absent if not set (null = use calculator)
        if (!array_key_exists('quoted_price_eur', $o)) {
            $o['quoted_price_eur'] = null;
        }

        $data['meta']['language']    = $data['meta']['language'] ?? 'fi';

        $data['contact']             = $data['contact'] ?? [];
        $data['contact']['name']     = $data['contact']['name'] ?? $data['customer']['name'];
        $data['contact']['email']    = $data['contact']['email'] ?? '';
        $data['contact']['phone']    = $data['contact']['phone'] ?? '';

        $data['notes']               = $data['notes'] ?? '';

        $svc = &$data['additional_services'];
        if (!isset($svc) || !is_array($svc)) {
            $svc = [];
        }
        foreach (['ennakkoroudaus', 'song_requests_extra', 'extra_performances',
                  'background_music_h', 'live_album'] as $key) {
            $svc[$key] = (int) ($svc[$key] ?? 0);
        }
        $svc['discount_eur'] = (float) ($svc['discount_eur'] ?? 0.0);

        $data['song_requests'] = $data['song_requests'] ?? [];

        return $data;
    }
}

<?php

declare(strict_types=1);

/**
 * Calculates the gig quote based on the pricing formula in
 * old-files/sales/price-calculation-flow.txt.
 *
 * All monetary constants are in euros (floats). The returned totals
 * are rounded to 2 decimal places; individual row values likewise.
 *
 * The GROSS total is what the customer pays (and what appears in the
 * quote email). Individual row net/VAT/gross amounts match the invoice.
 */
class PriceCalculator
{
    // --- Core pricing constants (net, EUR) ---

    /** Base price for the performance. */
    const BASE_PRICE_NET = 1500.00;

    /** Travel cost rate: €1.00 per straight-line km from Turku city centre. */
    const DISTANCE_RATE = 1.00;

    /** On-season Saturday markup (discretionary, May–September). */
    const TIER1_MARKUP = 50.00;

    /** High-demand date markup (discretionary, 3–4+ concurrent inquiries). */
    const TIER2_MARKUP = 75.00;

    /** Buukkaa-bandi.fi brokerage fee (net). */
    const BUUKKAA_BANDI_FEE = 99.00;

    /**
     * Main vehicle mileage rate (Finnish Kilometrikorvaus):
     * base 0.55 + 4 passengers × 0.04 + trailer 0.10 = 0.81 €/km
     */
    const CAR1_RATE = 0.81;

    /** Auxiliary vehicle mileage rate (Finnish Kilometrikorvaus base): 0.55 €/km */
    const CAR2_RATE = 0.55;

    /** VAT rate for music performances. Subject to change; check current law. */
    const VAT_RATE = 0.135;

    // --- Additional service gross prices (€) ---
    // These are the prices shown to customers. Net = gross / (1 + VAT_RATE).

    const GROSS_ENNAKKOROUDAUS      = 200.00;
    const GROSS_SONG_REQUEST_EXTRA  = 100.00;
    const GROSS_EXTRA_PERFORMANCE   = 100.00;
    const GROSS_BACKGROUND_MUSIC_HR = 300.00;
    const GROSS_LIVE_ALBUM          = 300.00;

    /**
     * @param array $data Normalised inquiry data from InquiryParser::parse()
     * @return array {
     *     rows: array<{label: string, net: float, vat: float, gross: float}>,
     *     net_total: float,
     *     vat_total: float,
     *     gross_total: float
     * }
     */
    public function calculate(array $data): array
    {
        $rows    = [];
        $channel = $data['meta']['channel'];
        $dist    = $data['gig']['distances'];
        $order   = $data['order'];
        $svc     = $data['additional_services'];

        // Row 1: Musiikin esittäminen
        // = base + (distance × rate) + dynamic pricing markup(s)
        $musicNet = self::BASE_PRICE_NET
            + (float) $dist['from_turku_km'] * self::DISTANCE_RATE
            + ($order['dynamic_pricing_tier1'] ? self::TIER1_MARKUP : 0.0)
            + ($order['dynamic_pricing_tier2'] ? self::TIER2_MARKUP : 0.0);

        $rows[] = $this->makeRow('Musiikin esittäminen', $musicNet);

        // Row 2: Buukkaa-bandi.fi välityspalkkio (if applicable)
        if ($channel === 'buukkaa_bandi') {
            $rows[] = $this->makeRow('buukkaa-bandi.fi välityspalkkio', self::BUUKKAA_BANDI_FEE);
        }

        // Row 3: Main vehicle mileage
        $car1Km = (float) $dist['car1_trip_km'];
        if ($car1Km > 0) {
            $rows[] = $this->makeRow('Matkakulut (auto 1)', $car1Km * self::CAR1_RATE);
        }

        // Row 4: Auxiliary vehicle mileage
        $car2Km = (float) $dist['car2_trip_km'];
        if ($car2Km > 0) {
            $rows[] = $this->makeRow('Matkakulut (auto 2)', $car2Km * self::CAR2_RATE);
        }

        // Row 5: Other travel expenses
        $otherEur = (float) $dist['other_travel_costs_eur'];
        if ($otherEur > 0) {
            $rows[] = $this->makeRow('Muut matkakulut', $otherEur);
        }

        // Additional services: gross → net via gross / (1 + VAT_RATE)
        $additionalDefs = [
            ['label' => 'Ennakkoroudaus',              'qty' => $svc['ennakkoroudaus'],      'gross_unit' => self::GROSS_ENNAKKOROUDAUS],
            ['label' => 'Ylimääräiset toivekappaleet', 'qty' => $svc['song_requests_extra'], 'gross_unit' => self::GROSS_SONG_REQUEST_EXTRA],
            ['label' => 'Ylimääräiset ohjelmanumerot', 'qty' => $svc['extra_performances'],  'gross_unit' => self::GROSS_EXTRA_PERFORMANCE],
            ['label' => 'Taustamusiikki',              'qty' => $svc['background_music_h'],  'gross_unit' => self::GROSS_BACKGROUND_MUSIC_HR],
            ['label' => 'Live-albumi',                 'qty' => $svc['live_album'],           'gross_unit' => self::GROSS_LIVE_ALBUM],
        ];

        foreach ($additionalDefs as $def) {
            if ($def['qty'] > 0) {
                $net = $def['qty'] * $def['gross_unit'] / (1 + self::VAT_RATE);
                $rows[] = $this->makeRow($def['label'], $net);
            }
        }

        // Discount: customer-facing gross discount → negative net row
        $discountGross = (float) $svc['discount_eur'];
        if ($discountGross > 0) {
            $discountNet = -$discountGross / (1 + self::VAT_RATE);
            $rows[] = $this->makeRow('Alennus', $discountNet);
        }

        $netTotal   = round(array_sum(array_column($rows, 'net')),   2);
        $vatTotal   = round(array_sum(array_column($rows, 'vat')),   2);
        $grossTotal = round(array_sum(array_column($rows, 'gross')), 2);

        return [
            'rows'        => $rows,
            'net_total'   => $netTotal,
            'vat_total'   => $vatTotal,
            'gross_total' => $grossTotal,
        ];
    }

    private function makeRow(string $label, float $net): array
    {
        $net   = round($net,            2);
        $vat   = round($net * self::VAT_RATE, 2);
        $gross = round($net + $vat,     2);
        return ['label' => $label, 'net' => $net, 'vat' => $vat, 'gross' => $gross];
    }
}

#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * process_inquiry.php — CLI quote processor
 *
 * Usage:
 *   php cli/process_inquiry.php <inquiry.yaml> [options]
 *
 * Options:
 *   --type=<type>     Template type to render. Default: quote
 *                     Allowed: quote, venue-familiar-quote, sorry-were-booked
 *   --output=<mode>   Output mode. Default: email
 *                     Allowed: email, summary, both
 *
 * Examples:
 *   php cli/process_inquiry.php inquiry-260530-konsta-hannula.yaml
 *   php cli/process_inquiry.php inquiry-260530-konsta-hannula.yaml --output=both
 *   php cli/process_inquiry.php inquiry-260530-konsta-hannula.yaml --type=sorry-were-booked | pbcopy
 *
 * No Docker or database required. Reads YAML, outputs filled email template.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/InquiryParser.php';
require_once __DIR__ . '/lib/PriceCalculator.php';
require_once __DIR__ . '/lib/TemplateRenderer.php';

// ---- Argument parsing -------------------------------------------------------
// Manual parsing so options and positional args can appear in any order.

$inputFile    = null;
$templateType = 'quote';
$outputMode   = 'email';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--type=')) {
        $templateType = substr($arg, 7);
    } elseif (str_starts_with($arg, '--output=')) {
        $outputMode = substr($arg, 9);
    } elseif (!str_starts_with($arg, '--')) {
        $inputFile = $arg;
    }
}

if ($inputFile === null) {
    fwrite(STDERR, "Usage: php cli/process_inquiry.php <inquiry.yaml> [--type=quote] [--output=email]\n");
    exit(1);
}

$allowedTypes  = ['quote', 'venue-familiar-quote', 'sorry-were-booked'];
$allowedModes  = ['email', 'summary', 'both'];

if (!in_array($templateType, $allowedTypes, true)) {
    fwrite(STDERR, "Invalid --type '$templateType'. Allowed: " . implode(', ', $allowedTypes) . "\n");
    exit(1);
}

if (!in_array($outputMode, $allowedModes, true)) {
    fwrite(STDERR, "Invalid --output '$outputMode'. Allowed: " . implode(', ', $allowedModes) . "\n");
    exit(1);
}

// ---- Parse ------------------------------------------------------------------

$parser = new InquiryParser();

try {
    $data = $parser->parse($inputFile);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

// ---- Calculate price --------------------------------------------------------

$calculator = new PriceCalculator();
$priceResult = $calculator->calculate($data);

// Manual override: if quoted_price_eur is set in YAML, use it for the email
$grossForEmail = $data['order']['quoted_price_eur'] !== null
    ? (float) $data['order']['quoted_price_eur']
    : $priceResult['gross_total'];

// ---- Render template --------------------------------------------------------

$renderer = new TemplateRenderer();

try {
    $emailText = $renderer->render($data, $grossForEmail, $templateType);
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}

// ---- Output -----------------------------------------------------------------

if ($outputMode === 'summary' || $outputMode === 'both') {
    echo renderSummary($data, $priceResult, $grossForEmail, $templateType, $renderer, $inputFile);
}

if ($outputMode === 'both') {
    echo "\n" . str_repeat('-', 72) . "\n\n";
}

if ($outputMode === 'email' || $outputMode === 'both') {
    echo $emailText . "\n";
}

exit(0);

// ---- Summary renderer -------------------------------------------------------

function renderSummary(
    array $data,
    array $price,
    float $grossForEmail,
    string $templateType,
    TemplateRenderer $renderer,
    string $inputFile = ''
): string {
    $o = $data['order'];
    $d = $data['gig']['distances'];

    $templatePath = $renderer->resolveTemplatePath(
        $data['meta']['language'],
        $data['meta']['channel'],
        $data['customer']['type'],
        $templateType
    );

    $lines = [];
    $lines[] = "=== Inquiry Summary ===";
    $lines[] = "";
    $lines[] = "File:          " . $inputFile;
    $lines[] = "Customer:      " . $data['customer']['name'] . " (" . $data['customer']['type'] . ")";
    $lines[] = "Contact:       " . ($data['contact']['name'] ?? '—');
    $lines[] = "Gig date:      " . $data['gig']['date'];
    $lines[] = "Venue:         " . implode(', ', array_filter([
        $data['gig']['venue']['name']    ?? '',
        $data['gig']['venue']['city']    ?? '',
    ]));
    $lines[] = "Channel:       " . $data['meta']['channel'];
    $lines[] = "Order:         " . $o['sets'] . " × " . $o['set_duration_min'] . " min"
                                 . ($o['extras'] ? " + " . $o['extras'] : '');
    $lines[] = "Tier 1 markup: " . ($o['dynamic_pricing_tier1'] ? 'yes (+' . PriceCalculator::TIER1_MARKUP . ' €)' : 'no');
    $lines[] = "Tier 2 markup: " . ($o['dynamic_pricing_tier2'] ? 'yes (+' . PriceCalculator::TIER2_MARKUP . ' €)' : 'no');
    $lines[] = "";
    $lines[] = "=== Price Breakdown ===";
    $lines[] = "";
    $lines[] = sprintf("  %-38s %10s %10s %10s", "Row", "Net €", "VAT €", "Gross €");
    $lines[] = "  " . str_repeat('-', 70);

    foreach ($price['rows'] as $row) {
        $lines[] = sprintf(
            "  %-38s %10s %10s %10s",
            $row['label'],
            number_format($row['net'],   2, ',', ' '),
            number_format($row['vat'],   2, ',', ' '),
            number_format($row['gross'], 2, ',', ' ')
        );
    }

    $lines[] = "  " . str_repeat('-', 70);
    $lines[] = sprintf(
        "  %-38s %10s %10s %10s",
        "TOTAL",
        number_format($price['net_total'],   2, ',', ' '),
        number_format($price['vat_total'],   2, ',', ' '),
        number_format($price['gross_total'], 2, ',', ' ')
    );

    if ($data['order']['quoted_price_eur'] !== null) {
        $lines[] = "";
        $lines[] = "  ⚠  Manual override applied: quoted_price_eur = "
                 . $renderer->formatPrice($grossForEmail)
                 . "  (calculator result: " . $renderer->formatPrice($price['gross_total']) . ")";
    }

    $lines[] = "";
    $lines[] = "=== Email ===";
    $lines[] = "";
    $lines[] = "Template: " . $templatePath;
    $lines[] = "Price in email: " . $renderer->formatPrice($grossForEmail);

    return implode("\n", $lines) . "\n";
}

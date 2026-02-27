<?php

declare(strict_types=1);

/**
 * Fills in a sales mail template with inquiry-specific values.
 *
 * Template files live at:
 *   old-files/sales/{lang}/{channel}/{customer_type}/{template_type}.txt
 *
 * Substitutions applied:
 *   [ASIAKAS]    → greeting name (first name of contact, or customer name for companies)
 *   hintaan .    → hintaan X XXX,XX €.   (Finnish decimal format, inc. VAT)
 */
class TemplateRenderer
{
    private string $salesRoot;

    public function __construct()
    {
        // cli/lib/ → project root → old-files/sales/
        $this->salesRoot = dirname(__DIR__, 2) . '/old-files/sales';
    }

    /**
     * @param array  $data         Normalised inquiry data from InquiryParser
     * @param float  $grossTotal   Price to insert (inc. VAT), in euros
     * @param string $templateType e.g. 'quote', 'venue-familiar-quote', 'sorry-were-booked'
     * @return string Filled template text
     * @throws RuntimeException if template file not found
     */
    public function render(array $data, float $grossTotal, string $templateType): string
    {
        $path = $this->resolveTemplatePath(
            $data['meta']['language'],
            $data['meta']['channel'],
            $data['customer']['type'],
            $templateType
        );

        if (!file_exists($path)) {
            throw new RuntimeException(
                "Template not found: $path\n" .
                "Check that lang/channel/customer_type/template_type are correct."
            );
        }

        $text = file_get_contents($path);

        $text = $this->substituteCustomerName($text, $data);
        $text = $this->substitutePrice($text, $grossTotal);

        return $text;
    }

    /**
     * Returns the resolved template path without checking existence.
     * Useful for summary output.
     */
    public function resolveTemplatePath(
        string $lang,
        string $channel,
        string $customerType,
        string $templateType
    ): string {
        $channelDir      = $this->mapChannel($channel);
        $customerTypeDir = $this->mapCustomerType($customerType);
        $fileName        = $templateType . '.txt';

        return implode('/', [$this->salesRoot, $lang, $channelDir, $customerTypeDir, $fileName]);
    }

    // -------------------------------------------------------------------------

    private function substituteCustomerName(string $text, array $data): string
    {
        $greetingName = $this->resolveGreetingName($data);
        return str_replace('[ASIAKAS]', $greetingName, $text);
    }

    /**
     * For weddings/other: first name of the contact (informal Finnish).
     * For companies: the full company name.
     */
    private function resolveGreetingName(array $data): string
    {
        if ($data['customer']['type'] === 'company') {
            return $data['customer']['name'];
        }

        $contactName = trim($data['contact']['name'] ?? '');
        if ($contactName === '') {
            return $data['customer']['name'];
        }

        // Return first word (first name) only
        return explode(' ', $contactName)[0];
    }

    /**
     * Inserts the formatted price into "hintaan ." → "hintaan X XXX,XX €."
     * The template has a literal space between "hintaan " and "." as a placeholder.
     */
    private function substitutePrice(string $text, float $grossTotal): string
    {
        $formatted = $this->formatPrice($grossTotal);
        return str_replace('hintaan .', "hintaan $formatted.", $text);
    }

    /**
     * Formats a euro amount in Finnish style: 2 289,00 €
     * (space as thousands separator, comma as decimal separator)
     */
    public function formatPrice(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' €';
    }

    private function mapChannel(string $channel): string
    {
        return match ($channel) {
            'buukkaa_bandi' => 'buukkaa-bandi',
            default         => $channel,   // 'mail' → 'mail'
        };
    }

    private function mapCustomerType(string $type): string
    {
        return match ($type) {
            'wedding' => 'weddings',
            'company' => 'companies',
            default   => $type,   // 'other' → 'other'
        };
    }
}

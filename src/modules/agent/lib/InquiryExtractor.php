<?php
declare(strict_types=1);

/**
 * Extracts structured gig fields from raw inquiry text using the Anthropic API.
 *
 * Uses tool_use with a fixed tool schema so the model always returns a typed
 * JSON object; missing fields come back as null.
 */
class InquiryExtractor
{
    private const MODEL      = 'claude-sonnet-4-6';
    private const API_URL    = 'https://api.anthropic.com/v1/messages';
    private const MAX_TOKENS = 1024;
    private const TIMEOUT    = 30;

    /**
     * Extract structured gig fields from a raw inquiry text.
     *
     * @param  string               $rawText  Pasted inquiry (email, message, etc.)
     * @return array<string, mixed>           Extracted fields; missing fields are null.
     * @throws RuntimeException               On API or curl error.
     */
    public static function extract(string $rawText): array
    {
        $apiKey = getenv('ANTHROPIC_API_KEY');
        if (!$apiKey) {
            throw new \RuntimeException('ANTHROPIC_API_KEY is not set.');
        }

        $tool = [
            'name'        => 'record_inquiry',
            'description' => 'Record structured gig inquiry data extracted from raw text. '
                           . 'Call this tool with all fields you can determine; use null for '
                           . 'any field that cannot be reliably extracted from the text.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'customer_name'      => [
                        'type'        => ['string', 'null'],
                        'description' => 'Legal or full name of the customer (company or person)',
                    ],
                    'customer_type'      => [
                        'type'        => ['string', 'null'],
                        'enum'        => ['wedding', 'company', 'other', null],
                        'description' => '"wedding" for private wedding receptions, '
                                       . '"company" for corporate events, '
                                       . '"other" for birthdays, festivals, and other private events',
                    ],
                    'gig_date'           => [
                        'type'        => ['string', 'null'],
                        'description' => 'Event date in YYYY-MM-DD format; null if not stated',
                    ],
                    'venue_name'         => [
                        'type'        => ['string', 'null'],
                        'description' => 'Name of the venue or location',
                    ],
                    'venue_address'      => [
                        'type'        => ['string', 'null'],
                        'description' => 'Street address of the venue',
                    ],
                    'venue_city'         => [
                        'type'        => ['string', 'null'],
                        'description' => 'City where the venue is located',
                    ],
                    'contact_first_name' => [
                        'type'        => ['string', 'null'],
                        'description' => 'First name of the person who sent the inquiry',
                    ],
                    'contact_last_name'  => [
                        'type'        => ['string', 'null'],
                        'description' => 'Last name of the person who sent the inquiry',
                    ],
                    'contact_email'      => [
                        'type'        => ['string', 'null'],
                        'description' => 'Email address of the contact person',
                    ],
                    'contact_phone'      => [
                        'type'        => ['string', 'null'],
                        'description' => 'Phone number of the contact person',
                    ],
                    'order_description'  => [
                        'type'        => ['string', 'null'],
                        'description' => 'Brief summary of what the customer is requesting '
                                       . '(number of performances, duration, special setup, etc.)',
                    ],
                    'notes'              => [
                        'type'        => ['string', 'null'],
                        'description' => 'Any other relevant details: budget hints, '
                                       . 'special requests, constraints, questions from the customer',
                    ],
                ],
                'required' => [
                    'customer_name', 'customer_type', 'gig_date',
                    'venue_name', 'venue_address', 'venue_city',
                    'contact_first_name', 'contact_last_name',
                    'contact_email', 'contact_phone',
                    'order_description', 'notes',
                ],
            ],
        ];

        $payload = json_encode([
            'model'       => self::MODEL,
            'max_tokens'  => self::MAX_TOKENS,
            'tools'       => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => 'record_inquiry'],
            'messages'    => [[
                'role'    => 'user',
                'content' => "Extract the gig inquiry details from the following text. "
                           . "The text may be in Finnish or English.\n\n---\n{$rawText}\n---",
            ]],
        ]);

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException('Anthropic API curl error: ' . $curlErr);
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !isset($data['content'])) {
            $msg = $data['error']['message'] ?? $response;
            throw new \RuntimeException('Anthropic API error (' . $httpCode . '): ' . $msg);
        }

        foreach ($data['content'] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && $block['name'] === 'record_inquiry') {
                return $block['input'];
            }
        }

        throw new \RuntimeException('Anthropic API did not return a tool_use block.');
    }
}

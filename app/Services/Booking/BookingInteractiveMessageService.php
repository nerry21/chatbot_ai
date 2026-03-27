<?php

namespace App\Services\Booking;

use Illuminate\Support\Str;

class BookingInteractiveMessageService
{
    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return array{text: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    public function departureTimeMenu(string $body, array $slots, ?string $footer = null): array
    {
        $options = array_values(array_filter(array_map(
            function (array $slot): ?array {
                $title = trim((string) ($slot['label'] ?? ''));
                $time = trim((string) ($slot['time'] ?? ''));

                if ($title === '' || $time === '') {
                    return null;
                }

                return [
                    'id' => 'departure_time:'.$time,
                    'title' => $title,
                    'description' => $time.' WIB',
                ];
            },
            $slots,
        )));

        $footer ??= 'Jika menu tidak tampil, balas angka atau jam yang dipilih.';

        if (count($options) <= 3) {
            return $this->buttonMessage(
                $body,
                array_map(
                    fn (array $option): array => ['id' => $option['id'], 'title' => $option['title']],
                    $options,
                ),
                $footer,
            );
        }

        return $this->listMessage(
            $body,
            'Pilih Jam',
            [[
                'title' => 'Jam keberangkatan',
                'rows' => $options,
            ]],
            $footer,
        );
    }

    /**
     * @param  array<int, string>  $locations
     * @return array{text: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    public function pickupLocationMenu(string $body, array $locations, ?string $footer = null): array
    {
        return $this->locationMenu(
            body: $body,
            buttonText: 'Pilih Lokasi',
            locations: $locations,
            prefix: 'pickup_location',
            sectionLabel: 'Lokasi jemput',
            footer: $footer ?? 'Jika menu tidak tampil, balas angka atau nama lokasi jemput.',
        );
    }

    /**
     * @param  array<int, string>  $locations
     * @return array{text: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    public function dropoffLocationMenu(string $body, array $locations, ?string $footer = null): array
    {
        return $this->locationMenu(
            body: $body,
            buttonText: 'Pilih Tujuan',
            locations: $locations,
            prefix: 'dropoff_location',
            sectionLabel: 'Lokasi antar',
            footer: $footer ?? 'Jika menu tidak tampil, balas angka atau nama lokasi tujuan.',
        );
    }

    /**
     * @param  array<int, array{id: string, title: string}>  $buttons
     * @return array{text: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    public function buttonMessage(string $body, array $buttons, ?string $footer = null): array
    {
        $interactiveButtons = array_values(array_map(
            fn (array $button): array => [
                'type' => 'reply',
                'reply' => [
                    'id' => $button['id'],
                    'title' => $button['title'],
                ],
            ],
            array_slice($buttons, 0, 3),
        ));

        if ($interactiveButtons === []) {
            return [
                'text' => $this->fallbackText($body, [], $footer),
                'message_type' => 'text',
                'outbound_payload' => [],
            ];
        }

        return [
            'text' => $this->fallbackText($body, array_map(
                fn (array $button): string => $button['title'],
                $interactiveButtons === []
                    ? $buttons
                    : array_slice($buttons, 0, count($interactiveButtons)),
            ), $footer),
            'message_type' => 'interactive',
            'outbound_payload' => [
                'interactive' => array_filter([
                    'type' => 'button',
                    'body' => ['text' => $body],
                    'footer' => $footer !== null ? ['text' => $footer] : null,
                    'action' => ['buttons' => $interactiveButtons],
                ]),
            ],
        ];
    }

    /**
     * @param  array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string|null}>}>  $sections
     * @return array{text: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    public function listMessage(
        string $body,
        string $buttonText,
        array $sections,
        ?string $footer = null,
    ): array {
        $interactiveSections = array_values(array_filter(array_map(
            function (array $section): ?array {
                $rows = array_values(array_filter(array_map(
                    function (array $row): ?array {
                        $title = trim((string) ($row['title'] ?? ''));

                        if ($title === '') {
                            return null;
                        }

                        $payload = [
                            'id' => (string) ($row['id'] ?? $title),
                            'title' => $title,
                        ];

                        if (filled($row['description'] ?? null)) {
                            $payload['description'] = (string) $row['description'];
                        }

                        return $payload;
                    },
                    array_slice($section['rows'] ?? [], 0, 10),
                )));

                if ($rows === []) {
                    return null;
                }

                return [
                    'title' => (string) ($section['title'] ?? 'Pilihan'),
                    'rows' => $rows,
                ];
            },
            array_slice($sections, 0, 10),
        )));

        $fallbackOptions = [];

        foreach ($interactiveSections as $section) {
            foreach ($section['rows'] as $row) {
                $fallbackOptions[] = $row['title'];
            }
        }

        if ($interactiveSections === []) {
            return [
                'text' => $this->fallbackText($body, [], $footer),
                'message_type' => 'text',
                'outbound_payload' => [],
            ];
        }

        return [
            'text' => $this->fallbackText($body, $fallbackOptions, $footer),
            'message_type' => 'interactive',
            'outbound_payload' => [
                'interactive' => array_filter([
                    'type' => 'list',
                    'body' => ['text' => $body],
                    'footer' => $footer !== null ? ['text' => $footer] : null,
                    'action' => [
                        'button' => $buttonText,
                        'sections' => $interactiveSections,
                    ],
                ]),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $options
     */
    public function fallbackText(string $body, array $options, ?string $footer = null): string
    {
        $lines = [trim($body)];

        if ($options !== []) {
            $lines[] = '';

            foreach (array_values($options) as $index => $option) {
                $lines[] = ($index + 1).'. '.$option;
            }
        }

        if (filled($footer)) {
            $lines[] = '';
            $lines[] = trim((string) $footer);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<int, string>  $locations
     * @return array{text: string, message_type: string, outbound_payload: array<string, mixed>}
     */
    private function locationMenu(
        string $body,
        string $buttonText,
        array $locations,
        string $prefix,
        string $sectionLabel,
        ?string $footer = null,
    ): array {
        $rows = array_values(array_filter(array_map(
            function (string $location) use ($prefix): ?array {
                $title = trim($location);

                if ($title === '') {
                    return null;
                }

                return [
                    'id' => $prefix.':'.$this->slugValue($title),
                    'title' => $title,
                ];
            },
            $locations,
        )));

        $sections = [];

        foreach (array_chunk($rows, 10) as $index => $chunk) {
            $sections[] = [
                'title' => $sectionLabel.' '.($index + 1),
                'rows' => $chunk,
            ];
        }

        return $this->listMessage($body, $buttonText, $sections, $footer);
    }

    private function slugValue(string $value): string
    {
        return Str::slug($value);
    }
}

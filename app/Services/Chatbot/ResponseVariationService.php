<?php

namespace App\Services\Chatbot;

class ResponseVariationService
{
    /**
     * @param  array<int, string>  $options
     */
    public function pick(array $options, ?string $seed = null): string
    {
        $options = array_values(array_filter(array_map(
            fn (mixed $option): string => is_string($option) ? trim($option) : '',
            $options,
        )));

        if ($options === []) {
            return '';
        }

        if ($seed === null || trim($seed) === '') {
            return $options[0];
        }

        $index = abs((int) crc32($seed)) % count($options);

        return $options[$index];
    }
}

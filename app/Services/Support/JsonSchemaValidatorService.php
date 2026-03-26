<?php

namespace App\Services\Support;

class JsonSchemaValidatorService
{
    /**
     * Validate that all required keys are present and non-null in $data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>    $requiredKeys
     */
    public function isValid(array $data, array $requiredKeys): bool
    {
        foreach ($requiredKeys as $key) {
            if (! array_key_exists($key, $data)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Merge $data with $defaults, ensuring every key in $defaults is present.
     * Existing values (including null) in $data are preserved as-is.
     * Extra keys in $data beyond the defaults are kept.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function withDefaults(array $data, array $defaults): array
    {
        return array_merge($defaults, $data);
    }

    /**
     * Validate required keys are present AND apply defaults for missing optional keys.
     * Returns null if any required key is absent.
     *
     * @param  array<string, mixed>       $data
     * @param  array<int, string>         $requiredKeys
     * @param  array<string, mixed>       $defaults     Defaults for optional keys.
     * @return array<string, mixed>|null
     */
    public function validateAndFill(array $data, array $requiredKeys, array $defaults = []): ?array
    {
        if (! $this->isValid($data, $requiredKeys)) {
            return null;
        }

        return $this->withDefaults($data, $defaults);
    }

    /**
     * Try to decode a JSON string and validate it.
     * Returns null if decoding or validation fails.
     *
     * @param  array<int, string>    $requiredKeys
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>|null
     */
    public function decodeAndValidate(
        string $json,
        array $requiredKeys = [],
        array $defaults = [],
    ): ?array {
        $decoded = json_decode($json, associative: true);

        if (! is_array($decoded)) {
            return null;
        }

        if (! empty($requiredKeys) && ! $this->isValid($decoded, $requiredKeys)) {
            return null;
        }

        return empty($defaults) ? $decoded : $this->withDefaults($decoded, $defaults);
    }

    /**
     * Sanitize a float value to be within [0.0, 1.0].
     * Useful for normalizing AI confidence scores.
     */
    public function clampConfidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return (float) max(0.0, min(1.0, $value));
    }
}

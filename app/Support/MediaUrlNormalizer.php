<?php

namespace App\Support;

final class MediaUrlNormalizer
{
    public static function normalize(?string $url): ?string
    {
        $normalized = trim((string) $url);
        if ($normalized === '') {
            return null;
        }

        $baseUrl = self::preferredBaseUrl();
        $baseParsed = $baseUrl !== '' ? parse_url($baseUrl) : null;

        if (str_starts_with($normalized, '//')) {
            $scheme = self::preferredScheme($baseParsed);

            return ($scheme !== '' ? $scheme : 'https').':'.$normalized;
        }

        if (! self::isAbsoluteUrl($normalized)) {
            if ($baseUrl === '') {
                return $normalized;
            }

            return rtrim($baseUrl, '/').'/'.ltrim($normalized, '/');
        }

        $mediaParsed = parse_url($normalized);
        if (! is_array($mediaParsed) || ! isset($mediaParsed['host'])) {
            return $normalized;
        }

        $targetScheme = self::preferredScheme($baseParsed);
        $currentScheme = strtolower((string) ($mediaParsed['scheme'] ?? ''));
        $targetHost = strtolower((string) (($baseParsed['host'] ?? null) ?: ''));
        $mediaHost = strtolower((string) ($mediaParsed['host'] ?? ''));

        if ($targetScheme === 'https' && $currentScheme === 'http' && $targetHost !== '' && $targetHost === $mediaHost) {
            $normalized = preg_replace('/^http:/i', 'https:', $normalized) ?? $normalized;
        }

        return $normalized;
    }

    private static function preferredBaseUrl(): string
    {
        $forwardedHost = trim((string) request()->headers->get('x-forwarded-host', ''));
        if ($forwardedHost !== '') {
            $scheme = self::preferredScheme();
            $host = trim(explode(',', $forwardedHost)[0]);

            return sprintf('%s://%s', $scheme !== '' ? $scheme : 'https', $host);
        }

        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl !== '') {
            return $appUrl;
        }

        return trim((string) request()->getSchemeAndHttpHost());
    }

    private static function preferredScheme(?array $baseParsed = null): string
    {
        $forwardedProto = trim((string) request()->headers->get('x-forwarded-proto', ''));
        if ($forwardedProto !== '') {
            return strtolower(trim(explode(',', $forwardedProto)[0]));
        }

        if (is_array($baseParsed) && isset($baseParsed['scheme'])) {
            return strtolower((string) $baseParsed['scheme']);
        }

        if (request()->isSecure()) {
            return 'https';
        }

        return '';
    }

    private static function isAbsoluteUrl(string $url): bool
    {
        return preg_match('/^[a-z][a-z0-9+\-.]*:/i', $url) === 1;
    }
}

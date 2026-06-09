<?php

namespace HeistaAddressCheck;

/**
 * Maps the configured environment to the Heista API base URL.
 */
final class PlatformEnvironment
{
    public const PRODUCTION  = 'production';
    public const DEVELOPMENT = 'development';

    private const URL_PRODUCTION  = 'https://services.admin.heista.de';
    private const URL_DEVELOPMENT = 'https://dev.services.admin.heista.de';

    public static function baseUrlFor(string $environment, string $devOverride = ''): string
    {
        if ($environment === self::DEVELOPMENT && $devOverride !== '') {
            return rtrim($devOverride, '/');
        }
        return $environment === self::DEVELOPMENT ? self::URL_DEVELOPMENT : self::URL_PRODUCTION;
    }
}

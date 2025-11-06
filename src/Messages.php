<?php

namespace Hearth\LicenseClient;

/**
 * Simple, non-configurable package messages.
 * Keep messages here so applications cannot easily override the blocked text.
 */
class Messages
{
    protected static array $messages = [
        'not_present' => "A valid license is required to run this application. Please contact support.",
        'invalid' => "The installed license appears to be invalid or corrupted. Please re-install your license.",
        'not_active' => "Your license is not active. Please activate your license or contact support.",
        'domain_mismatch' => "This license is not valid for this host.",
        'expired' => "Your license has expired. Please renew your license to continue.",
    ];

    public static function get(string $key): string
    {
        return static::$messages[$key] ?? static::$messages['invalid'];
    }
}

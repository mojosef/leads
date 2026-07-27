<?php

namespace mojosef\Leads\Support;

class PhoneNumber
{
    /**
     * Normalise a phone number to E.164 format (e.g. "07123 456789" =>
     * "+447123456789") for use in enhanced-conversion / offline-conversion
     * uploads, where Google and Meta expect E.164 before hashing.
     *
     * Returns null when the input is empty. Numbers that already carry an
     * international prefix ("+" or "00") are preserved; bare national numbers
     * beginning with "0" are converted using the given country calling code
     * (defaults to UK "44", matching the fleet's audience).
     */
    public static function toE164(?string $number, string $countryCode = '44'): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        $cleaned = preg_replace('/[^0-9+]/', '', $number) ?? '';

        if (str_starts_with($cleaned, '+')) {
            return '+'.preg_replace('/[^0-9]/', '', $cleaned);
        }

        if (str_starts_with($cleaned, '00')) {
            return '+'.substr($cleaned, 2);
        }

        if (str_starts_with($cleaned, '0')) {
            return '+'.$countryCode.substr($cleaned, 1);
        }

        if (str_starts_with($cleaned, $countryCode)) {
            return '+'.$cleaned;
        }

        return '+'.$countryCode.$cleaned;
    }
}

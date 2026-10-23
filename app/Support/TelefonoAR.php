<?php

namespace App\Support;

/**
 * Argentine phone numbers in the international WhatsApp form: 549 + area
 * code + subscriber number (10 digits), without "+", the trunk 0 or the
 * mobile 15 prefix.
 */
final class TelefonoAR
{
    public static function paraWhatsapp(?string $telefono): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $telefono);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '549') && strlen($digits) === 13) {
            return $digits;
        }

        if (str_starts_with($digits, '54') && strlen($digits) === 12) {
            return '549'.substr($digits, 2);
        }

        $digits = ltrim($digits, '0');

        // Area code (2-4 digits) followed by the local mobile prefix 15.
        if (strlen($digits) === 12) {
            foreach ([2, 3, 4] as $area) {
                if (substr($digits, $area, 2) === '15') {
                    $digits = substr($digits, 0, $area).substr($digits, $area + 2);
                    break;
                }
            }
        }

        return strlen($digits) === 10 ? '549'.$digits : null;
    }
}

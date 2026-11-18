<?php

namespace App;

/**
 * Mobile numbers are stored as international digits without "+"
 * (966551234567), which is also the format WhatsApp expects.
 *
 * The service runs in Saudi Arabia; Palestinian numbers (+970 / +972) can
 * be enabled through AUTH_PHONE_COUNTRIES so the team can test the
 * WhatsApp codes from their own phones.
 */
class PhoneNumber
{
    /**
     * Supported countries: calling code => label and the national mobile
     * number without its leading zero.
     *
     * @var array<string, array{label: string, flag: string, pattern: string}>
     */
    public const COUNTRIES = [
        '966' => ['label' => 'السعودية', 'flag' => '🇸🇦', 'pattern' => '5\d{8}'],
        '970' => ['label' => 'فلسطين', 'flag' => '🇵🇸', 'pattern' => '5[69]\d{7}'],
        '972' => ['label' => 'فلسطين (972)', 'flag' => '🇵🇸', 'pattern' => '5\d{8}'],
    ];

    /**
     * Calling codes accepted by this installation, the first one is the default.
     *
     * @return list<string>
     */
    public static function allowedCountries(): array
    {
        $codes = array_values(array_intersect(
            array_map('trim', (array) config('auth.phone_countries')),
            array_keys(self::COUNTRIES),
        ));

        return $codes === [] ? ['966'] : $codes;
    }

    public static function defaultCountry(): string
    {
        return self::allowedCountries()[0];
    }

    /**
     * Turn what the user typed into international digits, or null when it is
     * not a mobile number of an allowed country.
     *
     * Accepts Arabic-Indic digits, spaces and dashes, "+966…", "00966…",
     * "966…", or a national number ("05…" / "5…") read in $country.
     */
    public static function normalize(?string $input, ?string $country = null): ?string
    {
        $digits = self::digits($input);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        foreach (self::allowedCountries() as $code) {
            if (str_starts_with($digits, $code) && self::matches($code, substr($digits, strlen($code)))) {
                return $digits;
            }
        }

        $country = in_array($country, self::allowedCountries(), true) ? $country : self::defaultCountry();
        $national = str_starts_with($digits, '0') ? substr($digits, 1) : $digits;

        return self::matches($country, $national) ? $country.$national : null;
    }

    /**
     * Regular expression matching every stored number of an allowed country.
     */
    public static function validationPattern(): string
    {
        $alternatives = array_map(
            fn (string $code): string => $code.self::COUNTRIES[$code]['pattern'],
            self::allowedCountries(),
        );

        return '/^(?:'.implode('|', $alternatives).')$/';
    }

    /**
     * Readable form for screens: "+966 55 123 4567".
     */
    public static function format(?string $phone): string
    {
        if (! $phone) {
            return '';
        }

        foreach (array_keys(self::COUNTRIES) as $code) {
            if (str_starts_with($phone, $code)) {
                $national = substr($phone, strlen($code));

                return '+'.$code.' '.preg_replace('/^(\d{2})(\d{3})(\d+)$/', '$1 $2 $3', $national);
            }
        }

        return '+'.$phone;
    }

    /**
     * Latin digits only (Arabic-Indic and Persian digits converted).
     */
    public static function digits(?string $input): string
    {
        $latin = strtr((string) $input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return preg_replace('/\D/', '', $latin) ?? '';
    }

    private static function matches(string $country, string $national): bool
    {
        return (bool) preg_match('/^'.self::COUNTRIES[$country]['pattern'].'$/', $national);
    }
}

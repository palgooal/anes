<?php

namespace Tests\Feature;

use App\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string}>
     */
    public static function inputs(): array
    {
        return [
            'saudi national' => ['0551234567', '966', '966551234567'],
            'saudi without zero' => ['551234567', '966', '966551234567'],
            'saudi international with spaces' => ['+966 55 123 4567', null, '966551234567'],
            'saudi with 00 prefix' => ['00966551234567', null, '966551234567'],
            'arabic-indic digits' => ['٠٥٥١٢٣٤٥٦٧', '966', '966551234567'],
            'palestine jawwal' => ['0599123456', '970', '970599123456'],
            'palestine ooredoo' => ['056-912-3456', '970', '970569123456'],
            'palestine as +972' => ['+972 59 912 3456', null, '972599123456'],
            'international prefix wins over chosen country' => ['+970599123456', '966', '970599123456'],
            'unknown country falls back to the default' => ['0551234567', '44', '966551234567'],
            'landline is rejected' => ['0112345678', '966', null],
            'too short' => ['05512345', '966', null],
            'palestine prefix that is not mobile' => ['0529123456', '970', null],
            'empty' => ['', '966', null],
        ];
    }

    #[DataProvider('inputs')]
    public function test_normalizes_typed_numbers_to_international_digits(string $input, ?string $country, ?string $expected): void
    {
        config(['auth.phone_countries' => ['966', '970', '972']]);

        $this->assertSame($expected, PhoneNumber::normalize($input, $country));
    }

    public function test_countries_that_are_not_enabled_are_rejected(): void
    {
        config(['auth.phone_countries' => ['966']]);

        $this->assertNull(PhoneNumber::normalize('+970599123456'));
        $this->assertSame('966599123456', PhoneNumber::normalize('0599123456', '970'));
        $this->assertSame(1, preg_match(PhoneNumber::validationPattern(), '966551234567'));
        $this->assertSame(0, preg_match(PhoneNumber::validationPattern(), '970599123456'));
    }

    public function test_unknown_codes_in_configuration_are_ignored(): void
    {
        config(['auth.phone_countries' => ['1', ' 970 ']]);

        $this->assertSame(['970'], PhoneNumber::allowedCountries());

        config(['auth.phone_countries' => ['']]);

        $this->assertSame(['966'], PhoneNumber::allowedCountries());
    }

    public function test_formats_numbers_for_display(): void
    {
        $this->assertSame('+966 55 123 4567', PhoneNumber::format('966551234567'));
        $this->assertSame('+970 59 912 3456', PhoneNumber::format('970599123456'));
        $this->assertSame('', PhoneNumber::format(null));
    }
}

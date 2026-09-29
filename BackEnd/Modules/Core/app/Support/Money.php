<?php

namespace Modules\Core\Support;

final class Money
{
    /**
     * Format an integer amount of halalas as a localized currency string.
     *
     * Money::format(190000) === '1,900.00 SAR'
     */
    public static function format(int $halalas, string $currency = 'SAR'): string
    {
        return number_format($halalas / 100, 2, '.', ',').' '.$currency;
    }
}

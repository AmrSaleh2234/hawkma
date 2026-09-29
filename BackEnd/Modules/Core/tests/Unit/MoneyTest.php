<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Support\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_formats_a_whole_amount(): void
    {
        $this->assertSame('1,900.00 SAR', Money::format(190000));
    }

    public function test_it_formats_zero(): void
    {
        $this->assertSame('0.00 SAR', Money::format(0));
    }

    public function test_it_formats_halalas_fraction(): void
    {
        $this->assertSame('0.05 SAR', Money::format(5));
    }

    public function test_it_formats_with_another_currency(): void
    {
        $this->assertSame('450,000.50 USD', Money::format(45000050, 'USD'));
    }
}

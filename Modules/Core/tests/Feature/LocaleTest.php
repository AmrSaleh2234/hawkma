<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->get('/api/v1/test-locale', function () {
            throw new BusinessException(ErrorCode::SlotNotAvailable);
        });
    }

    public function test_accept_language_en_returns_english_message(): void
    {
        $this->getJson('/api/v1/test-locale', ['Accept-Language' => 'en'])
            ->assertJsonPath('message', 'This time slot is no longer available.');
    }

    public function test_accept_language_ar_returns_arabic_message(): void
    {
        $this->getJson('/api/v1/test-locale', ['Accept-Language' => 'ar'])
            ->assertJsonPath('message', 'هذا الموعد لم يعد متاحاً.');
    }

    public function test_default_locale_is_arabic_without_a_header(): void
    {
        // Symfony's test client sends "en-us,en;q=0.5" when no header is given,
        // so an empty header simulates a client that does not send one.
        $this->getJson('/api/v1/test-locale', ['Accept-Language' => ''])
            ->assertJsonPath('message', 'هذا الموعد لم يعد متاحاً.');
    }

    public function test_unsupported_locale_falls_back_to_arabic(): void
    {
        $this->getJson('/api/v1/test-locale', ['Accept-Language' => 'fr'])
            ->assertJsonPath('message', 'هذا الموعد لم يعد متاحاً.');
    }
}

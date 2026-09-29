<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use RuntimeException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ExceptionRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('api/v1/test-errors')->group(function () {
            Route::get('validation', function () {
                throw ValidationException::withMessages(['email' => 'The email field is required.']);
            });
            Route::get('authentication', function () {
                throw new AuthenticationException;
            });
            Route::get('authorization', function () {
                throw new AuthorizationException('This action is unauthorized.');
            });
            Route::get('access-denied', function () {
                throw new AccessDeniedHttpException;
            });
            Route::get('spatie-unauthorized', function () {
                throw UnauthorizedException::forPermissions(['view-users']);
            });
            Route::get('model-not-found', function () {
                throw (new ModelNotFoundException)->setModel(\stdClass::class, 99);
            });
            Route::get('not-found', function () {
                throw new NotFoundHttpException;
            });
            Route::get('throttle', function () {
                throw new ThrottleRequestsException('Too Many Attempts.');
            });
            Route::get('business', function () {
                throw new BusinessException(ErrorCode::SlotNotAvailable);
            });
            Route::get('server', function () {
                throw new RuntimeException('boom');
            });
            Route::get('method-not-allowed', fn () => 'ok');
        });
    }

    public function test_validation_exception_returns_422_envelope(): void
    {
        $response = $this->getJson('/api/v1/test-errors/validation');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The email field is required.')
            ->assertJsonPath('errors.email.0', 'The email field is required.');
    }

    public function test_authentication_exception_returns_401(): void
    {
        $this->getJson('/api/v1/test-errors/authentication')
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_authorization_exception_returns_403(): void
    {
        $this->getJson('/api/v1/test-errors/authorization')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_access_denied_http_exception_returns_403(): void
    {
        $this->getJson('/api/v1/test-errors/access-denied')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_spatie_unauthorized_exception_returns_403(): void
    {
        $this->getJson('/api/v1/test-errors/spatie-unauthorized')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_model_not_found_returns_404(): void
    {
        $this->getJson('/api/v1/test-errors/model-not-found')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_not_found_http_exception_returns_404(): void
    {
        $this->getJson('/api/v1/test-errors/not-found')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_unknown_api_url_returns_404_json_not_html(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_method_not_allowed_returns_405(): void
    {
        $this->postJson('/api/v1/test-errors/method-not-allowed')
            ->assertStatus(405)
            ->assertJsonPath('error_code', 'METHOD_NOT_ALLOWED');
    }

    public function test_throttle_exception_returns_429(): void
    {
        $this->getJson('/api/v1/test-errors/throttle')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_REQUESTS');
    }

    public function test_business_exception_returns_its_status_and_code(): void
    {
        $this->getJson('/api/v1/test-errors/business')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'SLOT_NOT_AVAILABLE')
            ->assertJsonPath('errors', []);
    }

    public function test_generic_throwable_returns_500_and_hides_details_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/v1/test-errors/server')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'SERVER_ERROR')
            ->assertJsonPath('message', __('core::errors.SERVER_ERROR'));
    }

    public function test_generic_throwable_shows_details_when_debug_is_on(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/test-errors/server')
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'SERVER_ERROR')
            ->assertJsonPath('message', 'boom');
    }
}

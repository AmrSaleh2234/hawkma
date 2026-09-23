<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromHeader
{
    public const SUPPORTED = ['ar', 'en'];

    public const DEFAULT_LOCALE = 'ar';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 2));

        app()->setLocale(in_array($locale, static::SUPPORTED, true) ? $locale : static::DEFAULT_LOCALE);

        return $next($request);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Plan §10.25: every api/v1 route must appear in the Postman collection.
 * Path params are normalised: Laravel `{booking}` and Postman `{{booking_id}}`
 * both become `{}`.
 */
class RouteCoverageTest extends TestCase
{
    /** @return array<string, true> set of "METHOD /normalised/path" */
    private function collectionPairs(): array
    {
        $json = file_get_contents(base_path('postman/GCMC-API.postman_collection.json'));
        $collection = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $pairs = [];
        $walk = function (array $items) use (&$walk, &$pairs): void {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);

                    continue;
                }
                $request = $item['request'] ?? null;
                if (! is_array($request) || ! isset($request['method'], $request['url']['path'])) {
                    continue;
                }
                $path = implode('/', $request['url']['path']);
                $path = preg_replace('/\{\{[^}]+\}\}/', '{}', $path);
                $pairs[strtoupper($request['method']).' /'.$path] = true;
            }
        };
        $walk($collection['item']);

        return $pairs;
    }

    public function test_every_api_route_is_in_the_postman_collection(): void
    {
        $pairs = $this->collectionPairs();

        $missing = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }
            $path = preg_replace('/\{[^}]+\}/', '{}', substr($uri, strlen('api/v1/')));

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                if (! isset($pairs[$method.' /'.$path])) {
                    $missing[] = $method.' /'.$path;
                }
            }
        }

        $this->assertSame([], $missing, "Routes missing from the Postman collection:\n".implode("\n", $missing));
    }
}

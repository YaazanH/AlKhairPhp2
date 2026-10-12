<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use JsonException;
use Tests\TestCase;

class PostmanCollectionContractTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_postman_collection_is_valid_and_covers_every_v1_api_path(): void
    {
        $collection = $this->readJson('docs/postman/AlKhair-Mobile-API.postman_collection.json');
        $requests = $this->collectionRequests($collection['item'] ?? []);
        $apiRequests = collect($requests)->filter(fn (array $request) => str_starts_with($request['path'], 'api/v1/'));

        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/'));
        $routePaths = $routes->map(fn (Route $route) => $this->normalizedPath($route->uri()))->unique()->sort()->values();
        $collectionPaths = $apiRequests->pluck('path')->unique()->sort()->values();

        $this->assertSame($routePaths->all(), $collectionPaths->all(), 'The Postman collection paths differ from the registered V1 API paths.');

        foreach ($apiRequests as $request) {
            $matchingRoutes = $routes->filter(fn (Route $route) => $this->normalizedPath($route->uri()) === $request['path']);
            $this->assertTrue(
                $matchingRoutes->contains(fn (Route $route) => in_array($request['method'], $route->methods(), true)),
                "{$request['method']} {$request['path']} is not a registered API operation.",
            );
        }
    }

    /**
     * @throws JsonException
     */
    public function test_postman_environment_defines_every_referenced_variable_without_credentials(): void
    {
        $collectionPath = base_path('docs/postman/AlKhair-Mobile-API.postman_collection.json');
        $collectionJson = file_get_contents($collectionPath);
        $this->assertNotFalse($collectionJson);
        $collection = json_decode($collectionJson, true, 512, JSON_THROW_ON_ERROR);
        $environment = $this->readJson('docs/postman/AlKhair-Mobile-Local.postman_environment.json');
        $environmentValues = collect($environment['values'] ?? [])->keyBy('key');

        preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', $collectionJson, $matches);
        $referencedVariables = collect($matches[1] ?? [])->unique()->sort()->values();

        $this->assertSame([], $referencedVariables->diff($environmentValues->keys())->values()->all(), 'The Postman environment is missing referenced variables.');
        $this->assertSame('http://127.0.0.1:8000', $environmentValues->get('base_url')['value'] ?? null);

        foreach (['login', 'password', 'token', 'new_password'] as $secretKey) {
            $this->assertSame('', $environmentValues->get($secretKey)['value'] ?? null, "The exported environment must not contain {$secretKey}.");
        }

        $this->assertSame('', $environmentValues->get('date')['value'] ?? null);
        $this->assertSame('', $environmentValues->get('date_from')['value'] ?? null);
        $this->assertSame('', $environmentValues->get('date_to')['value'] ?? null);
        $this->assertNotEmpty($collection['event'] ?? [], 'The collection must retain its shared pre-request initialization.');
    }

    public function test_mobile_routes_retain_authentication_active_account_and_login_throttling(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes());
        $loginRoute = $routes->first(fn (Route $route) => $route->uri() === 'api/v1/auth/token' && in_array('POST', $route->methods(), true));

        $this->assertNotNull($loginRoute);
        $this->assertContains('throttle:10,1', $loginRoute->middleware());

        $protectedRoutes = $routes->filter(fn (Route $route) => ($route->uri() === 'api/user' || str_starts_with($route->uri(), 'api/v1/')) && $route !== $loginRoute);
        $this->assertNotEmpty($protectedRoutes);

        foreach ($protectedRoutes as $route) {
            $this->assertContains('auth:sanctum', $route->middleware(), "{$route->uri()} is missing Sanctum authentication.");
            $this->assertContains('api.active', $route->middleware(), "{$route->uri()} is missing the active-account guard.");
        }
    }

    /**
     * @throws JsonException
     */
    protected function readJson(string $relativePath): array
    {
        $contents = file_get_contents(base_path($relativePath));
        $this->assertNotFalse($contents, "Unable to read {$relativePath}.");

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function collectionRequests(array $items): array
    {
        $requests = [];

        foreach ($items as $item) {
            if (isset($item['request']['method'], $item['request']['url']['raw'])) {
                $rawPath = preg_replace('#^\{\{base_url\}\}/#', '', $item['request']['url']['raw']);
                $rawPath = explode('?', (string) $rawPath, 2)[0];
                $requests[] = [
                    'method' => strtoupper($item['request']['method']),
                    'path' => $this->normalizedPath($rawPath),
                ];
            }

            if (isset($item['item']) && is_array($item['item'])) {
                array_push($requests, ...$this->collectionRequests($item['item']));
            }
        }

        return $requests;
    }

    protected function normalizedPath(string $path): string
    {
        return preg_replace('/\{(?:\{)?[^}]+\}(?:\})?/', '{}', ltrim($path, '/'));
    }
}

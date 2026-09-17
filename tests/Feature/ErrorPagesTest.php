<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WebsiteService;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public static function selectedLocales(): array
    {
        return ['Arabic' => ['ar'], 'English' => ['en']];
    }

    #[DataProvider('selectedLocales')]
    public function test_errors_before_the_page_loads_respect_the_selected_language(string $locale): void
    {
        config(['app.debug' => false]);
        Route::get('/localized-missing-user/{user}', fn (User $user) => $user->name)->middleware('web');
        Route::post('/localized-expired-form', fn () => 'Saved')->middleware('web');
        $this->app->instance(ValidateCsrfToken::class, new class(app(), app('encrypter')) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

        app()->setLocale($locale === 'ar' ? 'en' : 'ar');
        $this->withSession(['locale' => $locale, 'locale_user_selected' => true])
            ->get('/localized-missing-user/99999999')
            ->assertNotFound()
            ->assertSee('lang="'.$locale.'"', false)
            ->assertSee(trans('errors.pages.404.title', locale: $locale));

        app()->setLocale($locale === 'ar' ? 'en' : 'ar');
        $this->withSession(['locale' => $locale, 'locale_user_selected' => true])
            ->post('/localized-expired-form')
            ->assertStatus(419)
            ->assertSee('lang="'.$locale.'"', false)
            ->assertSee(trans('errors.pages.419.title', locale: $locale));

        app()->setLocale($locale === 'ar' ? 'en' : 'ar');
        $this->withSession(['locale' => $locale, 'locale_user_selected' => true])
            ->post('/login')
            ->assertRedirect('/login')
            ->assertSessionHas('status', trans('auth.session_expired', locale: $locale));
    }

    public static function errorStatuses(): array
    {
        $cases = [];

        foreach (['ar' => 'rtl', 'en' => 'ltr'] as $locale => $direction) {
            foreach ([400, 401, 402, 403, 404, 405, 408, 410, 413, 418, 419, 422, 429, 500, 501, 502, 503, 504] as $status) {
                $cases[$locale.'-'.$status] = [$locale, $direction, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('errorStatuses')]
    public function test_error_responses_are_localised_and_keep_their_status_and_headers(string $locale, string $direction, int $status): void
    {
        app()->setLocale($locale);
        config(['app.debug' => false]);

        $response = app(ExceptionHandler::class)->render(
            Request::create('/unavailable'),
            new HttpException($status, $status === 422 ? '' : 'Private exception details', headers: ['Retry-After' => '60']),
        );

        $content = $response->getContent();
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertStringContainsString('lang="'.$locale.'" dir="'.$direction.'"', $content);
        $this->assertStringContainsString(__('errors.label', ['code' => $status]), $content);
        $this->assertStringContainsString('<p class="error-page__label" aria-hidden="true">ERROR</p>', $content);
        $this->assertStringContainsString(__('ui.app.name'), $content);
        $this->assertStringContainsString('id="error-title"', $content);
        $this->assertStringNotContainsString('Private exception details', $content);
        $this->assertStringNotContainsString('errors.pages.', $content);
    }

    public function test_an_unknown_url_uses_the_designed_404_page(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee(__('errors.pages.404.title'))
            ->assertSee('href="'.url('/').'"', false);
    }

    public function test_a_server_failure_can_render_without_database_settings_or_built_assets(): void
    {
        config(['app.debug' => false]);
        app(Vite::class)->useBuildDirectory('missing-error-page-build');
        DB::shouldReceive('connection')->never();
        $this->mock(WebsiteService::class)->shouldNotReceive('siteSettings');

        $response = app(ExceptionHandler::class)->render(Request::create('/unavailable'), new \RuntimeException('Database unavailable'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString(__('errors.pages.500.title'), $response->getContent());
        $this->assertStringContainsString('href="'.url('/').'"', $response->getContent());
        $this->assertStringNotContainsString('Database unavailable', $response->getContent());
    }

    public function test_get_post_and_session_errors_offer_one_safe_home_link(): void
    {
        // Register only test routes, so the template sees the actual request method and URL.
        Route::get('/error-retry', fn () => abort(503));
        Route::post('/error-submit', fn () => abort(503));
        Route::post('/error-session', fn () => abort(419));

        $this->get('/error-retry?filter=active')
            ->assertStatus(503)
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('href="'.url('/error-retry').'?filter=active"', false);

        $this->post('/error-submit')
            ->assertStatus(503)
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('<form', false);

        $this->post('/error-session')
            ->assertStatus(419)
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee(__('errors.actions.home'));
    }

    public function test_json_errors_keep_their_json_response(): void
    {
        $this->getJson('/this-api-path-does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message'])
            ->assertDontSee('<!DOCTYPE html>', false);
    }

    public function test_actionable_validation_messages_are_preserved_as_escaped_text(): void
    {
        $message = __('finance.reports.period_already_generated').' <script>alert("test")</script>';
        $response = app(ExceptionHandler::class)->render(Request::create('/report'), new HttpException(422, $message));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(e($message), $response->getContent());
        $this->assertStringNotContainsString('<script>alert("test")</script>', $response->getContent());
    }

    public function test_error_pages_show_only_the_code_title_and_home_link(): void
    {
        $response = $this->get('/missing-page')->assertNotFound();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);

        $this->assertSame(1, $xpath->query('//main')->length);
        $this->assertSame(2, $xpath->query('//body//p')->length);
        $this->assertSame('ERROR', trim($xpath->query('//main//p')->item(0)->textContent));
        $this->assertSame('404', trim($xpath->query('//main//p')->item(1)->textContent));
        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame(1, $xpath->query('//a')->length);
        $this->assertSame(0, $xpath->query('//header | //footer | //nav | //aside | //form | //img | //svg')->length);
    }

    public function test_signed_in_errors_use_the_same_minimal_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/missing-dashboard-page')
            ->assertNotFound()
            ->assertSee(__('errors.pages.404.title'))
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee($user->name)
            ->assertDontSee('app-sidebar-shell', false);
    }

    public function test_missing_public_pages_use_the_same_minimal_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/pages/missing-page')
            ->assertNotFound()
            ->assertSee(__('errors.pages.404.title'))
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('public-header', false)
            ->assertDontSee('app-sidebar-shell', false);
    }

    public function test_unknown_urls_respect_the_selected_website_language(): void
    {
        $this->withSession(['locale' => 'en', 'locale_user_selected' => true])
            ->get('/missing-page')
            ->assertNotFound()
            ->assertSee('lang="en" dir="ltr"', false)
            ->assertSee('Page not found');
    }

    public function test_missing_built_assets_do_not_prevent_error_pages_from_rendering(): void
    {
        app(Vite::class)->useBuildDirectory('missing-error-page-build');

        $this->get('/missing-page')
            ->assertNotFound()
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee(__('errors.pages.404.title'));
    }
}

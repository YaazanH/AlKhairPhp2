<?php

namespace Tests\Feature;

use Tests\TestCase;

class CanonicalHostTest extends TestCase
{
    public function test_production_www_requests_redirect_to_the_configured_apex_host_before_session_handling(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.url', 'https://alkheir-mosque.com');

        $this->get('https://www.alkheir-mosque.com/login?source=shortcut')
            ->assertStatus(308)
            ->assertRedirect('https://alkheir-mosque.com/login?source=shortcut')
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
    }

    public function test_the_configured_production_host_is_not_redirected(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.url', 'https://alkheir-mosque.com');

        $this->get('https://alkheir-mosque.com/up')->assertOk();
    }

    public function test_unrelated_hosts_are_not_redirected(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.url', 'https://alkheir-mosque.com');

        $this->get('https://tenant.example.test/up')->assertOk();
    }
}

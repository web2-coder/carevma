<?php

namespace Tests\Feature;

use Tests\TestCase;

class SpaShellTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_and_direct_portal_urls_return_the_react_shell(): void
    {
        foreach (['/login', '/admin/dashboard', '/hr/dashboard', '/employee/dashboard', '/doctor/dashboard'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<div id="app"></div>', false);
        }
    }

    public function test_unauthenticated_json_api_is_rejected(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_unknown_portal_url_returns_spa_shell_for_react_404(): void
    {
        $this->get('/some-old-bookmark')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }
}

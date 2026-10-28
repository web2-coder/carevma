<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
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

    /**
     * The signed /complete-profile onboarding flow is retired; VA activation now goes
     * through the SPA's /reset-password route. Links in old invitation emails must
     * land on the React shell, not on a 403 from the signed middleware or a 500.
     */
    public function test_legacy_onboarding_urls_fall_through_to_the_spa_shell(): void
    {
        foreach (['/complete-profile?email=x%40y.test&token=abc', '/complete-profile/start/1', '/profile-completed/1'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('<div id="app"></div>', false);
        }

        foreach (['employee.completeProfile', 'employee.completeProfile.start', 'employee.profile.update', 'employee.completed', 'employee.auto-login'] as $name) {
            $this->assertFalse(Route::has($name), "Legacy route [{$name}] is still registered.");
        }
    }
}

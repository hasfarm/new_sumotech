<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The root route always redirects to the dashboard (see routes/web.php) — it never
     * renders a 200 page directly, so that's the actual correct behavior to assert here.
     */
    public function test_the_root_route_redirects_to_the_dashboard(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('dashboard'));
    }
}

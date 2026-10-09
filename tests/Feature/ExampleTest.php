<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Smoke tests that need NO database.
 *
 * IMPORTANT: do not add RefreshDatabase / DatabaseMigrations tests unless phpunit.xml points at a
 * separate, disposable test database. Those traits wipe every table — pointed at Supabase they
 * would delete KaffeTrack's live data.
 */
class ExampleTest extends TestCase
{
    public function test_health_check_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}

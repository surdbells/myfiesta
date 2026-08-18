<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The API host serves no public site of its own.
     *
     * The public site is a separate Angular application; this domain answers
     * the API and hosts the admin panel, so the root redirects there rather
     * than rendering a page.
     */
    public function test_the_root_redirects_to_the_admin_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_the_health_endpoint_reports_ok(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }
}

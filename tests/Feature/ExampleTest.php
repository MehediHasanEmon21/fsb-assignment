<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_root_returns_api_service_information_instead_of_the_laravel_page(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'SaaS Subscription & Tenant Management API is running.',
            ]);
    }

    public function test_unmatched_web_route_returns_the_standard_not_found_error(): void
    {
        $this->getJson('/sdfsdfs')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Requested resource not found.',
            ]);
    }
}

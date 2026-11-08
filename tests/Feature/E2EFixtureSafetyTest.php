<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E2EFixtureSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_command_refuses_the_normal_test_database_without_mutation(): void
    {
        $before = \DB::table('companies')->count();

        $this->artisan('hrms:e2e-fixture')->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employee_profiles', 0);
        $this->assertSame($before, \DB::table('companies')->count());
    }
}

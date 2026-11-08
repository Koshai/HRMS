<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DeploymentPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_configuration_is_rejected_without_exposing_secrets_or_changing_database(): void
    {
        config(['app.key' => 'sensitive-test-key-that-must-not-be-printed', 'database.password' => 'sensitive-test-password']);
        $before = \DB::table('migrations')->count();

        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('BLOCK  Production environment')
            ->expectsOutputToContain('BLOCK  HTTPS application URL')
            ->expectsOutputToContain('BLOCK  MySQL or MariaDB selected')
            ->assertFailed();

        $this->assertSame($before, \DB::table('migrations')->count());
    }

    public function test_statutory_activation_override_blocks_release_preflight(): void
    {
        config(['compliance.allow_statutory_activation' => true]);

        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('BLOCK  Statutory activation disabled')
            ->assertFailed();
    }

    public function test_database_outage_is_reported_without_exception_details(): void
    {
        $original = config('database.default');
        config(['database.default' => 'missing_connection']);

        $this->artisan('hrms:preflight')
            ->expectsOutputToContain('BLOCK  Database connection')
            ->expectsOutputToContain('BLOCK  No pending migrations')
            ->assertFailed();
        config(['database.default' => $original]);
    }

    public function test_release_configuration_passes_against_unchanged_fixture_database(): void
    {
        $sqlite = config('database.connections.sqlite');
        config(['app.env' => 'production', 'app.debug' => false, 'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://staging.example.invalid', 'session.encrypt' => true, 'session.secure' => true,
            'database.default' => 'mysql', 'database.connections.mysql' => $sqlite,
            'mail.default' => 'smtp', 'mail.from.address' => 'sender@staging.example.invalid']);
        Artisan::call('migrate', ['--database' => 'mysql', '--force' => true]);

        $status = Artisan::call('hrms:preflight');
        $this->assertSame(0, $status, Artisan::output());

        config(['database.default' => 'sqlite']);
    }
}

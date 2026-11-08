<?php

namespace Tests\Feature;

use App\Compliance\ComplianceEvent;
use App\Compliance\ComplianceService;
use App\Compliance\ComplianceSource;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use App\Payroll\PayrollEntry;
use App\Payroll\PayrollPolicy;
use App\Payroll\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComplianceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow('2026-11-08 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_official_register_and_versioned_review_pack_are_gated(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $service = app(ComplianceService::class);
        $this->assertSame(6, ComplianceSource::count());
        $this->assertSame('2026-07-06', ComplianceSource::where('code', 'BD-WHT-2026-AMEND')->sole()->published_on->toDateString());
        $pack = $service->create($hr, '2026-07-01', null);
        $this->assertSame(1, $pack->version);
        $this->expectValidation(fn () => $pack->fresh()->update(['status' => 'active']));
        $this->assertSame(4, $pack->rules()->count());
        $this->assertSame(1, $pack->events()->count());
        $service->submitForReview($hr, $pack);
        $this->assertSame('ready_for_legal_review', $pack->fresh()->status);
        $this->assertSame(2, ComplianceEvent::where('pack_id', $pack->id)->count());
        $this->expectValidation(fn () => $pack->fresh()->update(['effective_from' => '2026-08-01']));
        $this->expectValidation(fn () => $pack->rules()->firstOrFail()->update(['applicability' => 'general_candidate']));
        $this->expectValidation(fn () => ComplianceSource::where('code', 'BD-WHT-2026-AMEND')->sole()->update(['title' => 'Changed']));
        $this->expectValidation(fn () => $pack->events()->firstOrFail()->delete());
        foreach ([fn () => $service->submitForReview($hr, $pack), fn () => $service->attest($admin, $pack, 'External reviewer', 'SIGNED-CASE-1'),
            fn () => $service->activate($admin, $pack)] as $action) {
            try {
                $action();
                $this->fail('Unverified pack advanced');
            } catch (ValidationException) {
                $this->assertSame('ready_for_legal_review', $pack->fresh()->status);
            }
        }
        $this->assertSame(2, ComplianceEvent::where('pack_id', $pack->id)->count());
        $next = $service->create($hr, '2027-07-01', null);
        $this->assertSame(2, $next->version);
        $this->assertSame('ready_for_legal_review', $pack->fresh()->status);
    }

    public function test_invalid_dates_unauthorized_roles_and_foreign_company_are_rejected(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        $employee = User::factory()->create(['role' => Role::Employee]);
        $foreignCompany = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $foreign = User::factory()->create(['role' => Role::HR, 'company_id' => $foreignCompany]);
        $service = app(ComplianceService::class);
        foreach ([['2026-02-30', null], ['2026-08-01', '2026-07-31']] as [$from, $to]) {
            $this->expectValidation(fn () => $service->create($hr, $from, $to));
        }
        $pack = $service->create($hr, '2026-07-01', null);
        foreach ([$manager, $employee] as $actor) {
            try {
                $service->create($actor, '2026-07-01', null);
                $this->fail('Non-payroll role created a pack');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
            $this->actingAs($actor)->get(route('compliance.index'))->assertForbidden();
        }
        $unverified = User::factory()->unverified()->create(['role' => Role::HR]);
        $inactive = User::factory()->create(['role' => Role::HR]);
        EmployeeProfile::create(['company_id' => $inactive->company_id, 'user_id' => $inactive->id,
            'employee_number' => 'FICT-INACTIVE', 'name' => 'Inactive Fictional HR', 'status' => 'inactive']);
        foreach ([$unverified, $inactive] as $actor) {
            try {
                $service->create($actor, '2026-07-01', null);
                $this->fail('Ineligible HR created a pack');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $this->actingAs($foreign)->get(route('compliance.show', $pack))->assertNotFound();
        $this->post(route('compliance.submit', $pack))->assertNotFound();
        $this->post(route('compliance.activate', $pack))->assertNotFound();
        $this->assertSame('draft', $pack->fresh()->status);
    }

    public function test_preview_is_read_only_and_never_claims_zero_statutory_tax(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $pack = app(ComplianceService::class)->create($hr, '2026-07-01', null);
        $policy = PayrollPolicy::create(['company_id' => $hr->company_id, 'effective_from' => '2026-01-01', 'currency' => 'BDT',
            'proration_basis' => 'calendar_days', 'requires_legal_review' => true, 'actor_user_id' => $hr->id, 'reason' => 'Fictional']);
        $run = PayrollRun::create(['company_id' => $hr->company_id, 'period_start' => '2026-09-01', 'run_key' => 'compliance-test',
            'kind' => 'regular', 'selected_employee_ids' => [], 'status' => 'finalized', 'policy_id' => $policy->id, 'prepared_by_user_id' => $hr->id]);
        $subject = EmployeeProfile::create(['company_id' => $hr->company_id, 'employee_number' => 'FICT-PREVIEW', 'name' => 'Fictional Preview Subject']);
        PayrollEntry::create(['company_id' => $hr->company_id, 'run_id' => $run->id, 'employee_profile_id' => $subject->id,
            'gross_minor' => 3755000, 'deductions_minor' => 5000, 'net_minor' => 3750000, 'snapshot' => ['fictional' => true]]);
        $before = $run->fresh()->toArray();
        $result = app(ComplianceService::class)->preview($hr, $pack, $run);
        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['estimated_statutory_minor']);
        $this->assertSame(3755000, $result['baseline_gross_minor']);
        $this->assertSame(5000, $result['baseline_deductions_minor']);
        $this->assertSame(3750000, $result['baseline_net_minor']);
        $this->assertCount(5, $result['warnings']);
        $this->assertContains('Employee #'.$subject->id.': applicability_missing', $result['warnings']);
        $this->assertSame($result, app(ComplianceService::class)->preview($hr, $pack, $run));
        $this->assertSame($before, $run->fresh()->toArray());
        $this->assertSame(3750000, PayrollEntry::sole()->net_minor);
        $this->actingAs($hr)->get(route('compliance.show', ['pack' => $pack, 'run_id' => $run->id]))->assertOk()->assertSee('Not calculated');
        app()->setLocale('bn');
        $this->get(route('compliance.index'))->assertOk()->assertSee('বাংলাদেশ কমপ্লায়েন্স');
        app()->setLocale('en');
    }

    public function test_provisional_applicability_is_dated_auditable_and_company_scoped(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = EmployeeProfile::create(['company_id' => $hr->company_id, 'employee_number' => 'FICT-1', 'name' => 'Fictional Subject']);
        $service = app(ComplianceService::class);
        $row = $service->recordApplicability($hr, $employee, '2026-07-01', '2026-12-31', 'statutory_worker_candidate', 'general_candidate', 'Fictional classification for review');
        $this->assertSame('provisional', $row->review_status);
        $this->assertSame($hr->id, $row->actor_user_id);
        $this->expectValidation(fn () => $service->recordApplicability($hr, $employee, '2026-12-01', null, 'managerial_candidate', 'epz_candidate', 'Overlap'));
        $this->expectValidation(fn () => $service->recordApplicability($hr, $employee, '2027-01-01', null, 'unknown', 'epz_candidate', 'Unknown category'));
        $foreignCompany = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $foreign = User::factory()->create(['role' => Role::HR, 'company_id' => $foreignCompany]);
        $this->actingAs($foreign)->post(route('compliance.applicability', $employee), ['effective_from' => '2027-01-01',
            'worker_category' => 'unresolved', 'establishment_regime' => 'unresolved', 'reason' => 'No access'])->assertNotFound();
    }

    private function expectValidation(callable $action): void
    {
        try {
            $action();
            $this->fail('Invalid date accepted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }
}

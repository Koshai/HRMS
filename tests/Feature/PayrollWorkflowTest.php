<?php

namespace Tests\Feature;

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Leave\LeaveRequest;
use App\Leave\LeaveType;
use App\Models\User;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use App\Payroll\PayrollEntry;
use App\Payroll\PayrollPayment;
use App\Payroll\PayrollReviewRevision;
use App\Payroll\PayrollRun;
use App\Payroll\PayrollService;
use App\Scheduling\ShiftScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PayrollWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function staff(User $user, string $number, string $start = '2026-10-01'): EmployeeProfile
    {
        $e = EmployeeProfile::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'employee_number' => $number, 'name' => $user->name, 'started_on' => $start]);
        $d = Department::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Operations']);
        $p = Position::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Officer']);
        $l = Location::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Dhaka'], ['timezone' => 'Asia/Dhaka']);
        app(AssignmentService::class)->assign($e, $d, $p, $l, CarbonImmutable::parse($start), null, null);

        return $e;
    }

    private function setupPayroll(User $hr, EmployeeProfile $employee, int $salary = 3100000): array
    {
        $s = app(PayrollService::class);
        $s->policy($hr, ['effective_from' => '2026-01-01', 'effective_to' => null, 'currency' => 'BDT', 'proration_basis' => 'calendar_days', 'reason' => 'Test-only policy']);
        $s->compensation($hr, $employee, ['effective_from' => '2026-01-01', 'effective_to' => null, 'currency' => 'BDT', 'monthly_base_minor' => $salary, 'components' => [['name' => 'Transport', 'side' => 'earning', 'amount_minor' => 310000]], 'reason' => 'Test salary']);

        return [$s, $s->createRun($hr, '2026-10-01', 'regular', [$employee->id])];
    }

    public function test_regular_run_retry_requires_identical_preparer_and_selection(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $otherHr = User::factory()->create(['role' => Role::HR]);
        $first = $this->staff(User::factory()->create(), 'P-1');
        $second = $this->staff(User::factory()->create(), 'P-2');
        [$service, $run] = $this->setupPayroll($hr, $first);
        $this->assertSame($run->id, $service->createRun($hr, '2026-10-01', 'regular', [$first->id])->id);
        foreach ([[$hr, [$second->id]], [$otherHr, [$first->id]]] as [$actor, $selection]) {
            try {
                $service->createRun($actor, '2026-10-01', 'regular', $selection);
                $this->fail('Conflicting pay run retry accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('run', $e->errors());
            }
        }
        $this->assertSame(1, PayrollRun::count());
    }

    public function test_exact_proration_separate_approval_release_and_payment_retries(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1', '2026-10-16');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        $service->calculate($hr, $run);
        $entry = PayrollEntry::sole();
        $this->assertSame(1760000, (int) $entry->gross_minor); // 16 of 31 days, base plus allowance.
        $this->assertSame($entry->gross_minor, $entry->net_minor);
        $this->actingAs($worker)->get(route('payroll.mine'))->assertOk()->assertDontSee('2026-10');
        $this->get(route('payroll.payslip', $entry))->assertNotFound();
        try {
            $service->finalize($hr, $run);
            $this->fail('Preparer approved own run');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approval', $e->errors());
        }
        $service->finalize($admin, $run);
        $service->release($hr, $run);
        $this->get(route('payroll.payslip', $entry))->assertOk()->assertSee('17,600.00');
        $payment = ['amount_minor' => 1000000, 'paid_on' => '2026-11-07', 'reference' => 'MANUAL-1', 'idempotency_key' => 'retry-1'];
        $service->payment($hr, $entry, $payment);
        $service->payment($hr, $entry, $payment);
        $this->assertSame(1, PayrollPayment::count());
        $this->assertSame(760000, $entry->net_minor - $entry->payments()->sum('amount_minor'));
        $csv = $this->actingAs($hr)->get(route('payroll.export', $run))->assertOk()->streamedContent();
        $this->assertStringContainsString('1760000,0,1760000,1000000,760000', $csv);
        try {
            $service->payment($hr, $entry, ['amount_minor' => 800000, 'paid_on' => '2026-11-07', 'reference' => 'MANUAL-2', 'idempotency_key' => 'retry-2']);
            $this->fail('Overpayment accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount_minor', $e->errors());
        }
    }

    public function test_manager_and_other_employee_cannot_access_salary_or_payslip(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        $worker = User::factory()->create();
        $other = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        $this->staff($other, 'P-2');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        $service->calculate($hr, $run);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $service->finalize($admin, $run);
        $service->release($hr, $run);
        $entry = PayrollEntry::sole();
        $this->actingAs($manager)->get(route('payroll.index'))->assertForbidden();
        $this->get(route('payroll.compensation', $employee))->assertForbidden();
        $this->get(route('payroll.team', ['month' => '2026-10']))->assertOk()->assertDontSee('31,000.00');
        $this->post(route('payroll.recommend', $employee), ['business_date' => '2026-10-01', 'recommendation' => 'Not my direct report'])->assertForbidden();
        $this->actingAs($other)->get(route('payroll.payslip', $entry))->assertNotFound();
        $this->get(route('payroll.team'))->assertForbidden();
        $this->actingAs($worker)->get(route('payroll.payslip', $entry))->assertOk();
    }

    public function test_cross_company_guesses_and_duplicate_regular_run_are_rejected(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        $otherCompanyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $otherHr = User::factory()->create(['role' => Role::HR, 'company_id' => $otherCompanyId]);
        $this->actingAs($otherHr)->get(route('payroll.show', $run))->assertNotFound();
        $this->post(route('payroll.compensation.store', $employee), ['effective_from' => '2026-11-01'])->assertNotFound();
        $this->actingAs($hr)->post(route('payroll.runs.store'), ['month' => '2026-10', 'kind' => 'regular', 'employee_ids' => [$employee->id]])->assertRedirect(route('payroll.show', $run));
        $this->assertSame(1, PayrollRun::count());
    }

    public function test_compensation_versions_do_not_overlap_and_midmonth_change_is_exact(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        $service = app(PayrollService::class);
        $service->policy($hr, ['effective_from' => '2026-01-01', 'currency' => 'BDT', 'proration_basis' => 'calendar_days', 'reason' => 'Test']);
        $base = ['currency' => 'BDT', 'components' => [], 'reason' => 'Salary change'];
        $service->compensation($hr, $employee, $base + ['effective_from' => '2026-01-01', 'effective_to' => '2026-10-15', 'monthly_base_minor' => 3100000]);
        try {
            $service->compensation($hr, $employee, $base + ['effective_from' => '2026-10-15', 'monthly_base_minor' => 6200000]);
            $this->fail('Overlap accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }
        $service->compensation($hr, $employee, $base + ['effective_from' => '2026-10-16', 'monthly_base_minor' => 6200000]);
        $run = $service->createRun($hr, '2026-10-01', 'regular', [$employee->id]);
        $service->calculate($hr, $run);
        $entry = PayrollEntry::sole();
        $this->assertSame(4700000, (int) $entry->gross_minor);
        $this->assertCount(2, $entry->snapshot['lines']);
    }

    public function test_missing_punch_is_reviewed_without_automatic_deduction_and_recalculation_is_required(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        CarbonImmutable::setTestNow('2026-09-30 12:00:00 UTC');
        $shiftService = app(ShiftScheduleService::class);
        $shift = $shiftService->createTemplate($hr, $employee->assignments()->first()->location, ['code' => 'ONE', 'name' => 'One day', 'starts_at_local' => '09:00', 'ends_at_local' => '17:00', 'unpaid_break_minutes' => 30, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0]);
        $shiftService->assign($hr, $employee, $shift, '2026-10-01', '2026-10-01', [4], 'Test shift', 'one-day');
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $this->assertCount(1, $service->unresolved($run));
        $service->calculate($hr, $run);
        $entry = PayrollEntry::sole();
        $this->assertSame(3410000, (int) $entry->gross_minor);
        $this->assertSame(0, (int) $entry->deductions_minor);
        $this->assertCount(1, $service->unresolved($run));
        try {
            $service->finalize($admin, $run->fresh());
            $this->fail('Unresolved shift approved');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approval', $e->errors());
        }
        $service->review($hr, $run, $employee, '2026-10-01', 'neutral', 0, 'Approved no pay change after review');
        $this->assertSame([], $service->unresolved($run));
        try {
            $service->finalize($admin, $run->fresh());
            $this->fail('Stale calculation approved');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approval', $e->errors());
        }
        $service->calculate($hr, $run);
        $service->finalize($admin, $run->fresh());
        $this->assertSame(3410000, (int) PayrollEntry::where('run_id', $run->id)->sole()->net_minor);
    }

    public function test_supplemental_correction_does_not_rewrite_finalized_salary(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        $service->calculate($hr, $run);
        $service->finalize($admin, $run->fresh());
        $original = PayrollEntry::sole();
        $snapshot = $original->snapshot;
        $supplemental = $service->createRun($hr, '2026-10-01', 'supplemental', [$employee->id], 'Correction to original');
        $service->adjustment($hr, $supplemental, $employee, ['kind' => 'correction_credit', 'amount_minor' => 12345, 'idempotency_key' => 'correct-1', 'reason' => 'Verified earlier omission']);
        $service->calculate($hr, $supplemental);
        $service->finalize($admin, $supplemental->fresh());
        $this->assertSame($snapshot, $original->fresh()->snapshot);
        $this->assertSame(12345, (int) PayrollEntry::where('run_id', $supplemental->id)->sole()->net_minor);
    }

    public function test_unverified_and_inactive_staff_cannot_open_payroll(): void
    {
        $unverified = User::factory()->unverified()->create(['role' => Role::HR]);
        $this->actingAs($unverified)->get(route('payroll.index'))->assertRedirect(route('verification.notice'));
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = $this->staff($hr, 'P-HR');
        $employee->update(['status' => 'inactive']);
        $this->actingAs($hr)->get(route('payroll.index'))->assertRedirect(route('login'));
    }

    public function test_conflicting_financial_retries_are_rejected_without_mutation(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        $adjustment = ['kind' => 'bonus', 'amount_minor' => 25000, 'idempotency_key' => 'bonus-1', 'reason' => 'Fictional award'];
        $first = $service->adjustment($hr, $run, $employee, $adjustment);
        $this->assertSame($first->id, $service->adjustment($hr, $run, $employee, $adjustment)->id);
        try {
            $service->adjustment($hr, $run, $employee, array_replace($adjustment, ['amount_minor' => 30000]));
            $this->fail('Conflicting adjustment retry accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }
        $review = $service->review($hr, $run, $employee, '2026-10-01', 'neutral', 0, 'No pay change');
        $this->assertSame($review->id, $service->review($hr, $run, $employee, '2026-10-01', 'neutral', 0, 'No pay change')->id);
        try {
            $service->review($hr, $run, $employee, '2026-10-01', 'unpaid', 1000, 'Different decision');
            $this->fail('Conflicting review retry accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('business_date', $e->errors());
        }
        $service->calculate($hr, $run);
        $service->finalize($admin, $run->fresh());
        $entry = PayrollEntry::sole();
        $payment = ['amount_minor' => 100000, 'paid_on' => '2026-11-07', 'reference' => 'MANUAL-A', 'idempotency_key' => 'pay-1'];
        $paid = $service->payment($hr, $entry, $payment);
        $this->assertSame($paid->id, $service->payment($hr, $entry, $payment)->id);
        try {
            $service->payment($hr, $entry, array_replace($payment, ['amount_minor' => 200000]));
            $this->fail('Conflicting payment retry accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('idempotency_key', $e->errors());
        }
        try {
            $service->payment($hr, $entry, array_replace($payment, ['idempotency_key' => 'pay-2']));
            $this->fail('Duplicate payment reference accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }
        $this->assertSame(1, PayrollPayment::count());
    }

    public function test_payroll_service_rechecks_roles_and_company_on_mutations(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        foreach ([fn () => $service->compensation($manager, $employee, ['effective_from' => '2026-11-01']),
            fn () => $service->calculate($manager, $run),
            fn () => $service->finalize($manager, $run),
            fn () => $service->release($manager, $run)] as $action) {
            try {
                $action();
                $this->fail('Manager mutated payroll via service');
            } catch (AuthorizationException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $otherCompanyId = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $otherHr = User::factory()->create(['role' => Role::HR, 'company_id' => $otherCompanyId]);
        $run->company_id = $otherCompanyId;
        $employee->company_id = $otherCompanyId;
        foreach ([fn () => $service->calculate($otherHr, $run),
            fn () => $service->compensation($otherHr, $employee, ['effective_from' => '2026-11-01'])] as $action) {
            try {
                $action();
                $this->fail('Forged model company bypassed persisted tenant check');
            } catch (NotFoundHttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_reconciliation_csv_is_restricted_and_escapes_spreadsheet_formulas(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        $worker = User::factory()->create(['name' => '  =SUM(1,1)']);
        $employee = $this->staff($worker, '+P-1');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        $service->calculate($hr, $run);
        $this->actingAs($manager)->get(route('payroll.export', $run))->assertForbidden();
        $csv = $this->actingAs($hr)->get(route('payroll.export', $run))->assertOk()->streamedContent();
        $this->assertStringContainsString("'+P-1", $csv);
        $this->assertStringContainsString("'  =SUM(1,1)", $csv);
        $this->assertStringContainsString('3410000', $csv);
    }

    public function test_review_facts_require_audited_revision_and_finalized_artifacts_are_guarded(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $employee = $this->staff($worker, 'P-1');
        [$service, $run] = $this->setupPayroll($hr, $employee);
        $review = $service->review($hr, $run, $employee, '2026-10-01', 'neutral', 0, 'No pay change');
        $type = LeaveType::create(['company_id' => $hr->company_id, 'code' => 'TEST-PAID', 'name' => 'Fictional paid leave', 'is_paid' => true, 'is_active' => true]);
        LeaveRequest::create(['company_id' => $hr->company_id, 'employee_profile_id' => $employee->id,
            'leave_type_id' => $type->id, 'location_id' => $employee->assignments()->first()->location_id,
            'requester_user_id' => $worker->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-01',
            'partial' => null, 'units' => 2, 'status' => 'approved', 'idempotency_key' => 'test-paid']);
        try {
            $service->review($hr, $run, $employee, '2026-10-01', 'neutral', 0, 'No pay change');
            $this->fail('Changed leave facts accepted as exact retry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('business_date', $e->errors());
        }
        $service->calculate($hr, $run);
        try {
            $service->finalize($admin, $run->fresh());
            $this->fail('Stale non-exception review accepted for finalization');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approval', $e->errors());
        }
        $service->reviseReview($hr, $review, 'neutral', 0, 'Paid leave confirmed', 'Leave approved after original review');
        $this->assertSame(1, PayrollReviewRevision::count());
        $service->calculate($hr, $run);
        $service->finalize($admin, $run->fresh());
        $entry = PayrollEntry::sole();
        foreach ([fn () => $entry->update(['gross_minor' => 1]), fn () => $entry->delete(),
            fn () => $run->fresh()->update(['status' => 'draft']), fn () => $run->fresh()->delete(),
            fn () => $review->fresh()->update(['amount_minor' => 100])] as $change) {
            try {
                $change();
                $this->fail('Finalized financial record changed');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}

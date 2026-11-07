<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->char('currency', 3)->default('BDT');
            $t->string('proration_basis', 24);
            $t->boolean('requires_legal_review')->default(true);
            $t->foreignId('actor_user_id');
            $t->string('reason', 2000);
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('compensation_agreements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_profile_id');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->char('currency', 3);
            $t->unsignedBigInteger('monthly_base_minor');
            $t->json('components');
            $t->foreignId('actor_user_id');
            $t->string('reason', 2000);
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->index(['company_id', 'employee_profile_id', 'effective_from']);
            $t->foreign(['company_id', 'employee_profile_id'])->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->date('period_start');
            $t->string('run_key', 40);
            $t->string('kind', 16);
            $t->json('selected_employee_ids');
            $t->string('status', 20)->default('draft');
            $t->foreignId('policy_id');
            $t->timestamp('calculated_at')->nullable();
            $t->foreignId('prepared_by_user_id');
            $t->foreignId('approved_by_user_id')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->string('reason', 2000)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'run_key']);
            $t->foreign(['company_id', 'policy_id'])->references(['company_id', 'id'])->on('payroll_policies')->restrictOnDelete();
            $t->foreign(['company_id', 'prepared_by_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $t->foreign(['company_id', 'approved_by_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('payroll_attendance_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('run_id');
            $t->foreignId('employee_profile_id');
            $t->date('business_date');
            $t->string('source_status', 40);
            $t->string('decision', 20);
            $t->unsignedBigInteger('amount_minor')->default(0);
            $t->foreignId('actor_user_id');
            $t->string('reason', 2000);
            $t->timestamps();
            $t->unique(['company_id', 'run_id', 'employee_profile_id', 'business_date'], 'payroll_review_day');
            $t->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('payroll_runs')->restrictOnDelete();
            $t->foreign(['company_id', 'employee_profile_id'])->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('payroll_attendance_recommendations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_profile_id');
            $t->date('business_date');
            $t->string('observed_status', 40);
            $t->foreignId('actor_user_id');
            $t->string('recommendation', 2000);
            $t->timestamps();
            $t->unique(['company_id', 'employee_profile_id', 'business_date', 'actor_user_id'], 'payroll_recommendation_once');
            $t->foreign(['company_id', 'employee_profile_id'])->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('payroll_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('run_id');
            $t->foreignId('employee_profile_id');
            $t->string('kind', 20);
            $t->unsignedBigInteger('amount_minor');
            $t->string('idempotency_key', 128);
            $t->foreignId('actor_user_id');
            $t->string('reason', 2000);
            $t->timestamps();
            $t->unique(['company_id', 'run_id', 'idempotency_key'], 'payroll_adjustment_retry');
            $t->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('payroll_runs')->restrictOnDelete();
            $t->foreign(['company_id', 'employee_profile_id'])->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('payroll_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('run_id');
            $t->foreignId('employee_profile_id');
            $t->unsignedBigInteger('gross_minor');
            $t->unsignedBigInteger('deductions_minor');
            $t->unsignedBigInteger('net_minor');
            $t->json('snapshot');
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'run_id', 'employee_profile_id'], 'payroll_entry_once');
            $t->foreign(['company_id', 'run_id'])->references(['company_id', 'id'])->on('payroll_runs')->restrictOnDelete();
            $t->foreign(['company_id', 'employee_profile_id'])->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
        });
        Schema::create('payroll_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('entry_id');
            $t->unsignedBigInteger('amount_minor');
            $t->date('paid_on');
            $t->string('reference', 128);
            $t->string('idempotency_key', 128);
            $t->foreignId('actor_user_id');
            $t->timestamps();
            $t->unique(['company_id', 'entry_id', 'idempotency_key'], 'payroll_payment_retry');
            $t->unique(['company_id', 'entry_id', 'reference'], 'payroll_payment_reference');
            $t->foreign(['company_id', 'entry_id'])->references(['company_id', 'id'])->on('payroll_entries')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['payroll_payments', 'payroll_entries', 'payroll_adjustments', 'payroll_attendance_recommendations', 'payroll_attendance_reviews', 'payroll_runs', 'compensation_agreements', 'payroll_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

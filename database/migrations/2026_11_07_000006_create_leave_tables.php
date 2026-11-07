<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id']);
        });

        Schema::create('leave_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_type_id');
            $table->foreignId('location_id');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('annual_units')->default(0); // Half-day units.
            $table->boolean('allow_half_day')->default(false);
            $table->boolean('allow_negative')->default(false);
            $table->string('approval_role', 16)->default('manager');
            $table->string('working_weekdays', 32); // ISO weekdays, comma separated.
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'leave_type_id', 'location_id', 'effective_from'], 'leave_policy_lookup');
            $table->foreign(['company_id', 'leave_type_id'])->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'location_id'])->references(['company_id', 'id'])->on('locations')->restrictOnDelete();
        });

        Schema::create('leave_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id');
            $table->date('holiday_on');
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'location_id', 'holiday_on'], 'leave_holiday_unique');
            $table->foreign(['company_id', 'location_id'])->references(['company_id', 'id'])->on('locations')->restrictOnDelete();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_profile_id');
            $table->foreignId('leave_type_id');
            $table->foreignId('location_id');
            $table->foreignId('requester_user_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('partial', 16)->nullable();
            $table->unsignedInteger('units');
            $table->string('status', 24);
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 128);
            $table->timestamps();
            $table->unique(['company_id', 'requester_user_id', 'idempotency_key'], 'leave_request_retry');
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'employee_profile_id', 'starts_on', 'ends_on'], 'leave_request_overlap');
            $table->foreign(['company_id', 'employee_profile_id'])->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'])->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'location_id'])->references(['company_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['company_id', 'requester_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('leave_request_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_request_id');
            $table->foreignId('leave_policy_id');
            $table->unsignedSmallInteger('leave_year');
            $table->unsignedInteger('units');
            $table->timestamps();
            $table->unique(['company_id', 'leave_request_id', 'leave_policy_id', 'leave_year'], 'leave_request_policy_year_unique');
            $table->foreign(['company_id', 'leave_request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_policy_id'])->references(['company_id', 'id'])->on('leave_policies')->restrictOnDelete();
        });

        Schema::create('leave_balance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_profile_id');
            $table->foreignId('leave_type_id');
            $table->foreignId('leave_policy_id')->nullable();
            $table->foreignId('leave_request_id')->nullable();
            $table->foreignId('actor_user_id');
            $table->unsignedSmallInteger('leave_year');
            $table->integer('units'); // Grants/credits positive; consumption negative.
            $table->string('kind', 24);
            $table->string('source_key', 160);
            $table->string('reason', 2000);
            $table->timestamps();
            $table->unique(['company_id', 'source_key']);
            $table->index(['company_id', 'employee_profile_id', 'leave_type_id', 'leave_year'], 'leave_balance_lookup');
            $table->foreign(['company_id', 'employee_profile_id'])->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_type_id'])->references(['company_id', 'id'])->on('leave_types')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_policy_id'])->references(['company_id', 'id'])->on('leave_policies')->restrictOnDelete();
            $table->foreign(['company_id', 'leave_request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('leave_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('leave_request_id');
            $table->foreignId('actor_user_id');
            $table->string('action', 24);
            $table->string('reason', 2000);
            $table->timestamps();
            $table->foreign(['company_id', 'leave_request_id'])->references(['company_id', 'id'])->on('leave_requests')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['leave_decisions', 'leave_balance_entries', 'leave_request_charges', 'leave_requests', 'leave_holidays', 'leave_policies', 'leave_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

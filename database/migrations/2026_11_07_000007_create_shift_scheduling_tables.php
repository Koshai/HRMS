<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id');
            $table->string('code', 32);
            $table->string('name');
            $table->time('starts_at_local');
            $table->time('ends_at_local');
            $table->boolean('ends_next_day');
            $table->unsignedSmallInteger('unpaid_break_minutes')->default(0);
            $table->unsignedSmallInteger('grace_in_minutes')->default(0);
            $table->unsignedSmallInteger('grace_out_minutes')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id');
            $table->timestamps();
            $table->unique(['company_id', 'location_id', 'code'], 'shift_template_code');
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'location_id'], 'shift_template_location_fk')->references(['company_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by_user_id'], 'shift_template_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_profile_id');
            $table->foreignId('shift_template_id');
            $table->foreignId('supersedes_shift_assignment_id')->nullable();
            $table->foreignId('actor_user_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('weekdays', 32); // Explicit ISO weekdays, comma-separated.
            $table->string('idempotency_key', 128);
            $table->string('reason', 2000);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key'], 'shift_assignment_retry');
            $table->index(['company_id', 'employee_profile_id', 'starts_on', 'ends_on'], 'shift_assignment_dates');
            $table->foreign(['company_id', 'employee_profile_id'], 'shift_assignment_employee_fk')->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $table->foreign(['company_id', 'shift_template_id'], 'shift_assignment_template_fk')->references(['company_id', 'id'])->on('shift_templates')->restrictOnDelete();
            $table->foreign(['company_id', 'supersedes_shift_assignment_id'], 'shift_assignment_prior_fk')->references(['company_id', 'id'])->on('shift_assignments')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_user_id'], 'shift_assignment_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('shift_assignment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_assignment_id');
            $table->foreignId('actor_user_id');
            $table->date('previous_ends_on')->nullable();
            $table->date('new_ends_on');
            $table->string('reason', 2000);
            $table->timestamps();
            $table->unique(['company_id', 'shift_assignment_id', 'new_ends_on'], 'shift_revision_unique');
            $table->foreign(['company_id', 'shift_assignment_id'], 'shift_revision_assignment_fk')->references(['company_id', 'id'])->on('shift_assignments')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_user_id'], 'shift_revision_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignment_revisions');
        Schema::dropIfExists('shift_assignments');
        Schema::dropIfExists('shift_templates');
    }
};

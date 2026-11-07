<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_profile_id');
            $table->foreignId('actor_user_id');
            $table->string('action', 16);
            $table->string('source', 24);
            $table->dateTime('occurred_at');
            $table->dateTime('recorded_at');
            $table->date('business_date');
            $table->string('timezone', 64);
            $table->foreignId('correction_of_id')->nullable();
            $table->string('status', 16)->default('recorded');
            $table->text('reason')->nullable();
            $table->string('idempotency_key', 128);
            $table->timestamps();
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'employee_profile_id', 'business_date'], 'attendance_subject_date');
            $table->foreign(['company_id', 'employee_profile_id'], 'attendance_employee_fk')->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_user_id'], 'attendance_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'correction_of_id'], 'attendance_original_fk')->references(['company_id', 'id'])->on('attendance_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};

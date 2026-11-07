<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['company_id', 'id']);
        });

        foreach (['departments', 'positions', 'locations'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) use ($tableName) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->string('name');

                if ($tableName === 'locations') {
                    $table->string('timezone')->default('Asia/Dhaka');
                }

                $table->timestamps();
                $table->unique(['company_id', 'name']);
                $table->unique(['company_id', 'id']);
            });
        }

        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique();
            $table->string('employee_number', 64);
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'employee_number']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('employment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_profile_id');
            $table->foreignId('department_id');
            $table->foreignId('position_id');
            $table->foreignId('location_id');
            $table->foreignId('manager_employee_profile_id')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'employee_profile_id', 'starts_on', 'ends_on'], 'assignments_employee_dates');
            $table->index(['company_id', 'manager_employee_profile_id', 'starts_on', 'ends_on'], 'assignments_manager_dates');
            $table->foreign(['company_id', 'employee_profile_id'], 'assignments_employee_fk')->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $table->foreign(['company_id', 'department_id'], 'assignments_department_fk')->references(['company_id', 'id'])->on('departments')->restrictOnDelete();
            $table->foreign(['company_id', 'position_id'], 'assignments_position_fk')->references(['company_id', 'id'])->on('positions')->restrictOnDelete();
            $table->foreign(['company_id', 'location_id'], 'assignments_location_fk')->references(['company_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['company_id', 'manager_employee_profile_id'], 'assignments_manager_fk')->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employment_assignments');
        Schema::dropIfExists('employee_profiles');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'id']);
        });
    }
};

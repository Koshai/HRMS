<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['departments', 'positions', 'locations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        }

        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('status', 24)->default('active');
            $table->date('started_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', fn (Blueprint $table) => $table->dropColumn(['status', 'started_on']));
        foreach (['departments', 'positions', 'locations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_active'));
        }
    }
};

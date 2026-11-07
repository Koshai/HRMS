<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('correction_id');
            $table->foreignId('actor_user_id');
            $table->string('decision', 16);
            $table->text('reason');
            $table->timestamp('decided_at');
            $table->unique('correction_id');
            $table->index(['company_id', 'correction_id']);
            $table->foreign(['company_id', 'correction_id'], 'attendance_decision_correction_fk')->references(['company_id', 'id'])->on('attendance_records')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_user_id'], 'attendance_decision_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_decisions');
    }
};

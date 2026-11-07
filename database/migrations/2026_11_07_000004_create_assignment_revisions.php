<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employment_assignments', fn (Blueprint $table) => $table->unique(['company_id', 'id']));
        Schema::create('assignment_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('employment_assignment_id');
            $table->foreignId('actor_user_id');
            $table->json('before_values');
            $table->json('after_values');
            $table->timestamp('created_at');
            $table->foreign(['company_id', 'actor_user_id'], 'assignment_revisions_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'employment_assignment_id'], 'assignment_revisions_assignment_fk')->references(['company_id', 'id'])->on('employment_assignments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_revisions');
        Schema::table('employment_assignments', fn (Blueprint $table) => $table->dropUnique(['company_id', 'id']));
    }
};

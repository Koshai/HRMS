<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_attendance_reviews', function (Blueprint $table) {
            $table->char('source_fingerprint', 64)->nullable();
            $table->unique(['company_id', 'id'], 'pay_review_company_id');
        });

        Schema::create('payroll_review_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('review_id');
            $table->foreignId('actor_user_id');
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->string('reason', 2000);
            $table->timestamps();
            $table->foreign(['company_id', 'review_id'], 'pay_revision_review_fk')->references(['company_id', 'id'])->on('payroll_attendance_reviews')->restrictOnDelete();
            $table->foreign(['company_id', 'actor_user_id'], 'pay_revision_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_review_revisions');
        Schema::table('payroll_attendance_reviews', function (Blueprint $table) {
            $table->dropUnique('pay_review_company_id');
            $table->dropColumn('source_fingerprint');
        });
    }
};

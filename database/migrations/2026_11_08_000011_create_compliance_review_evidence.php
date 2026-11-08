<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_rules', function (Blueprint $t) {
            $t->unique(['company_id', 'id'], 'compliance_rule_company_id_unique');
        });
        Schema::create('compliance_review_evidence', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('pack_id');
            $t->foreignId('rule_id')->nullable();
            $t->foreignId('source_id')->nullable()->constrained('compliance_sources')->restrictOnDelete();
            $t->foreignId('supplied_by_user_id');
            $t->string('kind', 32);
            $t->string('reference', 500);
            $t->char('sha256', 64);
            $t->string('status', 24)->default('supplied_unverified');
            $t->timestamps();
            $t->foreign(['company_id', 'pack_id'], 'compliance_evidence_pack_fk')->references(['company_id', 'id'])->on('compliance_packs')->restrictOnDelete();
            $t->foreign(['company_id', 'supplied_by_user_id'], 'compliance_evidence_user_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $t->foreign(['company_id', 'rule_id'], 'compliance_evidence_rule_fk')->references(['company_id', 'id'])->on('compliance_rules')->restrictOnDelete();
            $t->index(['company_id', 'pack_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_review_evidence');
        Schema::table('compliance_rules', function (Blueprint $t) {
            $t->dropUnique('compliance_rule_company_id_unique');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_sources', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('title', 200);
            $t->string('url', 500);
            $t->string('kind', 24);
            $t->date('published_on')->nullable();
            $t->string('review_status', 24)->default('needs_clarification');
            $t->timestamps();
        });
        Schema::create('compliance_packs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('status', 24)->default('draft');
            $t->foreignId('created_by_user_id');
            $t->foreignId('reviewed_by_user_id')->nullable();
            $t->string('reviewer_name', 200)->nullable();
            $t->string('evidence_reference', 500)->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('activated_by_user_id')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'id']);
            $t->unique(['company_id', 'version']);
            $t->foreign(['company_id', 'created_by_user_id'], 'compliance_pack_creator_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $t->foreign(['company_id', 'reviewed_by_user_id'], 'compliance_pack_reviewer_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $t->foreign(['company_id', 'activated_by_user_id'], 'compliance_pack_activator_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('compliance_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('pack_id');
            $t->foreignId('source_id')->constrained('compliance_sources')->restrictOnDelete();
            $t->string('code', 40);
            $t->string('applicability', 40)->default('unresolved');
            $t->string('review_status', 24)->default('needs_clarification');
            $t->json('parameters')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'pack_id', 'code']);
            $t->foreign(['company_id', 'pack_id'], 'compliance_rule_pack_fk')->references(['company_id', 'id'])->on('compliance_packs')->restrictOnDelete();
        });
        Schema::create('compliance_applicabilities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_profile_id');
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->string('worker_category', 32);
            $t->string('establishment_regime', 32);
            $t->string('review_status', 24)->default('provisional');
            $t->string('reason', 1000);
            $t->foreignId('actor_user_id');
            $t->timestamps();
            $t->unique(['company_id', 'employee_profile_id', 'effective_from'], 'compliance_applicability_start');
            $t->foreign(['company_id', 'employee_profile_id'], 'compliance_applicability_employee_fk')->references(['company_id', 'id'])->on('employee_profiles')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_user_id'], 'compliance_applicability_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('compliance_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('pack_id');
            $t->foreignId('actor_user_id');
            $t->string('action', 32);
            $t->json('details');
            $t->timestamps();
            $t->foreign(['company_id', 'pack_id'], 'compliance_event_pack_fk')->references(['company_id', 'id'])->on('compliance_packs')->restrictOnDelete();
            $t->foreign(['company_id', 'actor_user_id'], 'compliance_event_actor_fk')->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        $now = now();
        DB::table('compliance_sources')->insert([
            ['code' => 'BD-LABOUR-2006', 'title' => 'Bangladesh Labour Act, 2006 — wage board chapter', 'url' => 'https://bdlaws.minlaw.gov.bd/act-952/chapter-details-1041.html?lang=bn', 'kind' => 'legislation', 'published_on' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BD-LABOUR-2026', 'title' => 'Bangladesh Labour (Amendment) Act, 2026, Act 43', 'url' => 'https://bdlaws.minlaw.gov.bd/act-print-1672.html', 'kind' => 'legislation', 'published_on' => '2026-04-10', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BD-TAX-2023', 'title' => 'Income Tax Act, 2023 (NBR acts index)', 'url' => 'https://nbr.gov.bd/regulations/acts/income-tax-acts/eng', 'kind' => 'legislation', 'published_on' => null, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BD-FINANCE-2026', 'title' => 'Finance Act, 2026 (NBR acts index)', 'url' => 'https://nbr.gov.bd/regulations/acts/finance-acts/eng', 'kind' => 'legislation', 'published_on' => '2026-07-06', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BD-WHT-2026', 'title' => 'Withholding Tax Rules, 2026 (NBR rules index)', 'url' => 'https://nbr.gov.bd/regulations/rules/income-tax-rules/eng', 'kind' => 'regulation', 'published_on' => '2026-06-12', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'BD-WHT-2026-AMEND', 'title' => 'Withholding Tax Rules, 2026 amendment (NBR rules index)', 'url' => 'https://nbr.gov.bd/regulations/rules/income-tax-rules/eng', 'kind' => 'amendment', 'published_on' => '2026-07-06', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_events');
        Schema::dropIfExists('compliance_applicabilities');
        Schema::dropIfExists('compliance_rules');
        Schema::dropIfExists('compliance_packs');
        Schema::dropIfExists('compliance_sources');
    }
};

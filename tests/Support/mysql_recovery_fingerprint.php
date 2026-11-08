<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.mysql.database');
if (app()->environment('production') || config('database.default') !== 'mysql'
    || ! in_array($database, ['hrms_test', 'hrms_restore_test'], true)) {
    throw new RuntimeException('Fingerprinting is restricted to the disposable CI MySQL databases.');
}

$tables = ['companies', 'users', 'departments', 'positions', 'locations', 'employee_profiles', 'employment_assignments',
    'attendance_records', 'attendance_decisions', 'shift_templates', 'shift_assignments', 'leave_types', 'leave_policies',
    'leave_requests', 'leave_balance_entries', 'leave_decisions', 'payroll_policies', 'compensation_agreements',
    'payroll_runs', 'payroll_entries', 'payroll_payments', 'compliance_sources', 'compliance_packs', 'compliance_rules',
    'compliance_events', 'compliance_review_evidence', 'migrations'];
$counts = [];
$hashes = [];
foreach ($tables as $table) {
    if (! Schema::hasTable($table)) {
        throw new RuntimeException('Required table missing from fictional restore.');
    }
    $rows = DB::table($table)->orderBy($table === 'migrations' ? 'migration' : 'id')->get()->toArray();
    $counts[$table] = count($rows);
    $hashes[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}
foreach (['users', 'employee_profiles', 'attendance_records', 'leave_balance_entries', 'payroll_entries', 'compliance_packs'] as $table) {
    if ($counts[$table] === 0) {
        throw new RuntimeException('Fictional restore fixture is incomplete.');
    }
}
foreach ([['employee_profiles', 'company_id', 'companies'], ['attendance_records', 'employee_profile_id', 'employee_profiles'],
    ['leave_balance_entries', 'employee_profile_id', 'employee_profiles'], ['payroll_entries', 'run_id', 'payroll_runs'],
    ['compliance_events', 'pack_id', 'compliance_packs']] as [$child, $column, $parent]) {
    $orphans = DB::table($child.' as c')->leftJoin($parent.' as p', 'c.'.$column, '=', 'p.id')->whereNull('p.id')->count();
    if ($orphans !== 0) {
        throw new RuntimeException('Orphaned fictional restore rows detected.');
    }
}

echo json_encode(['counts' => $counts, 'sha256' => $hashes], JSON_THROW_ON_ERROR).PHP_EOL;

<?php

namespace App\Attendance;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function record(User $actor, EmployeeProfile $employee, string $action, CarbonImmutable $occurredAt, string $idempotencyKey, ?AttendanceRecord $correctionOf = null, ?string $reason = null): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('view', $employee);

        if (! in_array($action, ['check_in', 'check_out'], true)) {
            throw ValidationException::withMessages(['action' => 'Unsupported attendance action.']);
        }

        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'An idempotency key of at most 128 characters is required.']);
        }

        if ($correctionOf !== null && ($correctionOf->company_id !== $employee->company_id || $correctionOf->employee_profile_id !== $employee->id || $correctionOf->correction_of_id !== null)) {
            throw ValidationException::withMessages(['correction_of' => 'The original record must belong to this employee.']);
        }

        if ($correctionOf !== null && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A correction reason is required.']);
        }

        if ($correctionOf !== null && ! in_array($actor->role, [Role::Admin, Role::HR, Role::Manager], true)) {
            throw ValidationException::withMessages(['correction_of' => 'Correction proposals require HR or manager access.']);
        }

        $source = match (true) {
            $employee->user_id === $actor->id => 'employee_self',
            in_array($actor->role, [Role::Admin, Role::HR], true) => 'hr_manual',
            $actor->role === Role::Manager => 'manager_manual',
            default => throw ValidationException::withMessages(['employee' => 'Attendance entry is not authorized.']),
        };

        $timezone = config('hrms.display_timezone', 'Asia/Dhaka');
        $utc = $occurredAt->utc();
        $attributes = [
            'company_id' => $employee->company_id,
            'employee_profile_id' => $employee->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'source' => $source,
            'occurred_at' => $utc->format('Y-m-d H:i:s'),
            'business_date' => $occurredAt->setTimezone($timezone)->toDateString(),
            'timezone' => $timezone,
            'correction_of_id' => $correctionOf?->id,
            'status' => $correctionOf ? 'pending' : 'recorded',
            'reason' => $reason,
        ];

        return DB::transaction(function () use ($employee, $idempotencyKey, $attributes) {
            $existing = AttendanceRecord::query()->where('company_id', $employee->company_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                foreach ($attributes as $key => $value) {
                    $actual = $existing->getRawOriginal($key);
                    if ($key === 'business_date') {
                        $actual = substr((string) $actual, 0, 10);
                    }
                    if ((string) $actual !== (string) $value) {
                        throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different attendance entry.']);
                    }
                }

                return $existing;
            }

            return AttendanceRecord::query()->create($attributes + [
                'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
                'idempotency_key' => $idempotencyKey,
            ]);
        });
    }
}

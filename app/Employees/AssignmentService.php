<?php

namespace App\Employees;

use App\Models\User;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function assign(
        EmployeeProfile $employee,
        Department $department,
        Position $position,
        Location $location,
        CarbonImmutable $startsOn,
        ?CarbonImmutable $endsOn = null,
        ?EmployeeProfile $manager = null,
    ): EmploymentAssignment {
        $this->validate($employee, $department, $position, $location, $startsOn, $endsOn, $manager);

        return DB::transaction(function () use ($employee, $department, $position, $location, $startsOn, $endsOn, $manager) {
            EmployeeProfile::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            $overlap = $employee->assignments()
                ->where(function ($query) use ($startsOn) {
                    $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn->toDateString());
                })
                ->when($endsOn, fn ($query) => $query->whereDate('starts_on', '<=', $endsOn->toDateString()))
                ->exists();

            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'The assignment dates overlap an existing assignment.']);
            }

            return $employee->assignments()->create([
                'company_id' => $employee->company_id,
                'department_id' => $department->getKey(),
                'position_id' => $position->getKey(),
                'location_id' => $location->getKey(),
                'manager_employee_profile_id' => $manager?->getKey(),
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
            ]);
        });
    }

    private function validate(EmployeeProfile $employee, Department $department, Position $position, Location $location, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, ?EmployeeProfile $manager, ?EmploymentAssignment $existing = null): void
    {
        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be on or after the start date.']);
        }

        foreach ([$department, $position, $location, $manager] as $related) {
            if ($related !== null && $related->getAttribute('company_id') !== $employee->company_id) {
                throw ValidationException::withMessages(['company_id' => 'Assignment records must belong to the employee company.']);
            }
        }

        foreach (['department' => $department, 'position' => $position, 'location' => $location] as $field => $reference) {
            if (in_array($reference->getAttribute('is_active'), [false, 0, '0'], true) && $existing?->getAttribute($field.'_id') !== $reference->id) {
                throw ValidationException::withMessages(['assignment' => 'Inactive organization records cannot be assigned.']);
            }
        }

        if ($manager !== null && $manager->status === 'inactive' && $existing?->manager_employee_profile_id !== $manager->id) {
            throw ValidationException::withMessages(['manager' => 'The manager must be active.']);
        }

        if ($manager?->is($employee)) {
            throw ValidationException::withMessages(['manager' => 'An employee cannot manage themselves.']);
        }

        $ancestor = $manager;
        $seen = [];
        while ($ancestor !== null && ! isset($seen[$ancestor->id])) {
            $seen[$ancestor->id] = true;
            if ($ancestor->is($employee)) {
                throw ValidationException::withMessages(['manager' => 'This reporting relationship would create a loop.']);
            }
            $parentId = $ancestor->assignments()
                ->whereDate('starts_on', '<=', $startsOn->toDateString())
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn->toDateString()))
                ->value('manager_employee_profile_id');
            $ancestor = $parentId ? EmployeeProfile::query()->where('company_id', $employee->company_id)->whereKey($parentId)->first() : null;
        }
    }

    public function revise(EmploymentAssignment $assignment, User $actor, Department $department, Position $position, Location $location, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn = null, ?EmployeeProfile $manager = null): EmploymentAssignment
    {
        $employee = $assignment->employee;
        if ($actor->company_id !== $assignment->company_id) {
            throw ValidationException::withMessages(['company_id' => 'Assignment records must belong to the actor company.']);
        }
        $this->validate($employee, $department, $position, $location, $startsOn, $endsOn, $manager, $assignment);

        return DB::transaction(function () use ($assignment, $actor, $employee, $department, $position, $location, $startsOn, $endsOn, $manager) {
            EmployeeProfile::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $locked = $employee->assignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $overlap = $employee->assignments()->whereKeyNot($locked->id)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $startsOn->toDateString()))
                ->when($endsOn, fn ($query) => $query->whereDate('starts_on', '<=', $endsOn->toDateString()))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'The assignment dates overlap an existing assignment.']);
            }
            $before = [
                'department_id' => $locked->department_id,
                'position_id' => $locked->position_id,
                'location_id' => $locked->location_id,
                'manager_employee_profile_id' => $locked->manager_employee_profile_id,
                'starts_on' => CarbonImmutable::parse($locked->starts_on)->toDateString(),
                'ends_on' => $locked->ends_on ? CarbonImmutable::parse($locked->ends_on)->toDateString() : null,
            ];
            $after = [
                'department_id' => $department->id,
                'position_id' => $position->id,
                'location_id' => $location->id,
                'manager_employee_profile_id' => $manager?->id,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn?->toDateString(),
            ];
            if ($before !== $after) {
                $locked->update($after);
                DB::table('assignment_revisions')->insert([
                    'company_id' => $employee->company_id,
                    'employment_assignment_id' => $locked->id,
                    'actor_user_id' => $actor->id,
                    'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($after, JSON_THROW_ON_ERROR),
                    'created_at' => now('UTC'),
                ]);
            }

            return $locked;
        });
    }

    public function transfer(
        EmployeeProfile $employee,
        Department $department,
        Position $position,
        Location $location,
        CarbonImmutable $startsOn,
        User $actor,
        ?EmployeeProfile $manager = null,
    ): EmploymentAssignment {
        if ($actor->company_id !== $employee->company_id) {
            throw ValidationException::withMessages(['company_id' => 'Assignment records must belong to the actor company.']);
        }

        return DB::transaction(function () use ($employee, $department, $position, $location, $startsOn, $manager, $actor) {
            EmployeeProfile::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $current = $employee->assignments()->whereNull('ends_on')->lockForUpdate()->first();
            if ($current !== null) {
                if ($startsOn->toDateString() <= CarbonImmutable::parse($current->starts_on)->toDateString()) {
                    throw ValidationException::withMessages(['starts_on' => 'A transfer must start after the current assignment began.']);
                }
                $before = $current->only(['department_id', 'position_id', 'location_id', 'manager_employee_profile_id', 'starts_on', 'ends_on']);
                $current->update(['ends_on' => $startsOn->subDay()->toDateString()]);
                DB::table('assignment_revisions')->insert([
                    'company_id' => $employee->company_id,
                    'employment_assignment_id' => $current->id,
                    'actor_user_id' => $actor->id,
                    'before_values' => json_encode($before, JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($current->only(['department_id', 'position_id', 'location_id', 'manager_employee_profile_id', 'starts_on', 'ends_on']), JSON_THROW_ON_ERROR),
                    'created_at' => now('UTC'),
                ]);
            }

            return $this->assign($employee, $department, $position, $location, $startsOn, null, $manager);
        });
    }
}

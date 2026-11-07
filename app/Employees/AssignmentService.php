<?php

namespace App\Employees;

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
        if ($endsOn !== null && $endsOn->lessThan($startsOn)) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be on or after the start date.']);
        }

        foreach ([$department, $position, $location, $manager] as $related) {
            if ($related !== null && $related->getAttribute('company_id') !== $employee->company_id) {
                throw ValidationException::withMessages(['company_id' => 'Assignment records must belong to the employee company.']);
            }
        }

        if ($manager?->is($employee)) {
            throw ValidationException::withMessages(['manager' => 'An employee cannot manage themselves.']);
        }

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
}

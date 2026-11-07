<x-layouts::app :title="__('admin.assignment')">
    <section class="mx-auto max-w-3xl space-y-6 p-6">
        <a href="{{ route('employees.show', $employee) }}" class="text-teal-700 underline dark:text-teal-300">← {{ $employee->name }}</a>
        <h1 class="text-3xl font-semibold">{{ __('admin.assignment') }}</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $employee->employee_number }}</p>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950 dark:text-red-100"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ $assignment ? route('admin.employees.assignment.update', [$employee, $assignment]) : route('admin.employees.assign', $employee) }}" class="grid gap-5 rounded-xl border p-6 sm:grid-cols-2">
            @csrf @if($assignment) @method('PUT') @endif
            @foreach(['departments' => $departments, 'positions' => $positions, 'locations' => $locations] as $kind => $options)
                @php $field = ['departments' => 'department_id', 'positions' => 'position_id', 'locations' => 'location_id'][$kind]; @endphp
                <label>{{ __('admin.'.rtrim($kind, 's')) }}<select name="{{ $field }}" required class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"><option value="">{{ __('admin.none') }}</option>@foreach($options as $option)<option value="{{ $option->id }}" @selected(old($field, $assignment?->$field) == $option->id)>{{ $option->name }}</option>@endforeach</select></label>
            @endforeach
            <label>{{ __('admin.manager') }}<select name="manager_employee_profile_id" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"><option value="">{{ __('admin.none') }}</option>@foreach($managers as $manager)<option value="{{ $manager->id }}" @selected(old('manager_employee_profile_id', $assignment?->manager_employee_profile_id) == $manager->id)>{{ $manager->name }} ({{ $manager->employee_number }})</option>@endforeach</select></label>
            <label>{{ __('admin.starts_on') }}<input type="date" name="starts_on" value="{{ old('starts_on', $assignment?->starts_on?->format('Y-m-d')) }}" required class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            @if($assignment)<label>{{ __('admin.ends_on') }}<input type="date" name="ends_on" value="{{ old('ends_on', $assignment->ends_on?->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>@endif
            <div class="sm:col-span-2"><button class="rounded-lg bg-teal-700 px-5 py-2 text-white">{{ __('admin.save') }}</button></div>
        </form>
    </section>
</x-layouts::app>

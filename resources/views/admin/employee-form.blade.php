<x-layouts::app :title="$employee ? __('admin.edit_employee') : __('admin.new_employee')">
    <section class="mx-auto max-w-3xl space-y-6 p-6">
        <a href="{{ route('employees.index') }}" class="text-teal-700 underline dark:text-teal-300">← {{ __('admin.back') }}</a>
        <h1 class="text-3xl font-semibold">{{ $employee ? __('admin.edit_employee') : __('admin.new_employee') }}</h1>
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950 dark:text-red-100"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form method="POST" action="{{ $employee ? route('admin.employees.update', $employee) : route('admin.employees.store') }}" class="grid gap-5 rounded-xl border p-6 sm:grid-cols-2">
            @csrf @if($employee) @method('PUT') @endif
            <label>{{ __('admin.name') }}<input name="name" value="{{ old('name', $employee?->name) }}" required maxlength="255" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            <label>{{ __('admin.employee_number') }}<input name="employee_number" value="{{ old('employee_number', $employee?->employee_number) }}" required maxlength="64" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            <label>{{ __('admin.started_on') }}<input type="date" name="started_on" value="{{ old('started_on', $employee?->started_on?->format('Y-m-d')) }}" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            <label>{{ __('admin.status') }}<select name="status" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"><option value="active" @selected(old('status', $employee?->status ?? 'active') === 'active')>{{ __('admin.active') }}</option><option value="inactive" @selected(old('status', $employee?->status) === 'inactive')>{{ __('admin.inactive') }}</option></select></label>
            <div class="sm:col-span-2"><button class="rounded-lg bg-teal-700 px-5 py-2 text-white">{{ __('admin.save') }}</button></div>
        </form>
    </section>
</x-layouts::app>

<x-layouts::app :title="$employee->name">
    <article class="mx-auto max-w-5xl space-y-7">
        <a href="{{ route('employees.index') }}" class="text-sm font-medium text-teal-700 hover:underline dark:text-teal-300">← {{ __('employees.back') }}</a>
        <header>
            <p class="text-xs font-semibold tracking-widest text-teal-700 uppercase dark:text-teal-300">{{ __('employees.number') }} · {{ $employee->employee_number }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white">{{ $employee->name }}</h1>
            @if(session('status'))<p role="status" class="mt-3 rounded-lg bg-teal-50 p-3 text-teal-800 dark:bg-teal-950 dark:text-teal-100">{{ session('status') }}</p>@endif
            @can('manage-staff', auth()->user()->company)
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('admin.employees.edit', $employee) }}" class="rounded-lg border px-3 py-2">{{ __('admin.edit_employee') }}</a>
                    <a href="{{ route('admin.employees.assignment', $employee) }}" class="rounded-lg border px-3 py-2">{{ __('admin.assignment') }}</a>
                </div>
                @if($errors->any())<div role="alert" class="mt-3 text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                @if(! $employee->user_id)
                    <form method="POST" action="{{ route('admin.employees.provision', $employee) }}" class="mt-5 grid gap-3 rounded-xl border p-4 sm:grid-cols-3">
                        @csrf
                        <label>{{ __('admin.email') }}<input type="email" name="email" value="{{ old('email') }}" required class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
                        <label>{{ __('admin.role') }}<select name="role" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800">
                            @foreach(['employee','manager','hr','admin'] as $role)
                                @if($role !== 'admin' || auth()->user()->role === \App\Enums\Role::Admin)<option value="{{ $role }}">{{ ucfirst($role) }}</option>@endif
                            @endforeach
                        </select></label>
                        <button class="self-end rounded-lg bg-teal-700 px-4 py-2 text-white">{{ __('admin.provision') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.employees.resend-invite', $employee) }}" class="mt-4">@csrf<button class="text-sm text-teal-700 underline dark:text-teal-300">{{ __('admin.resend_invite') }}</button></form>
                @endif
            @endcan
        </header>

        <section aria-labelledby="assignment-history">
            <h2 id="assignment-history" class="mb-4 text-lg font-semibold">{{ __('employees.assignment_history') }}</h2>
            @forelse ($employee->assignments as $assignment)
                <div class="mb-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <p class="mb-4 text-sm font-medium text-zinc-600 dark:text-zinc-300">
                        {{ $assignment->starts_on->format('Y-m-d') }} – {{ $assignment->ends_on?->format('Y-m-d') ?? __('employees.current') }}
                    </p>
                    @can('manage-staff', auth()->user()->company)<a href="{{ route('admin.employees.assignment.edit', [$employee, $assignment]) }}" class="mb-4 inline-block text-sm text-teal-700 underline dark:text-teal-300">{{ __('admin.edit_assignment') }}</a>@endcan
                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('employees.department') }}</dt><dd class="font-medium">{{ $assignment->department->name }}</dd></div>
                        <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('employees.position') }}</dt><dd class="font-medium">{{ $assignment->position->name }}</dd></div>
                        <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('employees.location') }}</dt><dd class="font-medium">{{ $assignment->location->name }}</dd></div>
                        <div><dt class="text-zinc-500 dark:text-zinc-400">{{ __('employees.manager') }}</dt><dd class="font-medium">{{ $assignment->manager?->name ?? __('employees.none') }}</dd></div>
                    </dl>
                </div>
            @empty
                <p class="rounded-xl border border-zinc-200 p-5 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('employees.unassigned') }}</p>
            @endforelse
        </section>
    </article>
</x-layouts::app>

<x-layouts::app :title="$employee->name">
    <article class="mx-auto max-w-5xl space-y-7">
        <a href="{{ route('employees.index') }}" class="text-sm font-medium text-teal-700 hover:underline dark:text-teal-300">← {{ __('employees.back') }}</a>
        <header>
            <p class="text-xs font-semibold tracking-widest text-teal-700 uppercase dark:text-teal-300">{{ __('employees.number') }} · {{ $employee->employee_number }}</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white">{{ $employee->name }}</h1>
        </header>

        <section aria-labelledby="assignment-history">
            <h2 id="assignment-history" class="mb-4 text-lg font-semibold">{{ __('employees.assignment_history') }}</h2>
            @forelse ($employee->assignments as $assignment)
                <div class="mb-3 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <p class="mb-4 text-sm font-medium text-zinc-600 dark:text-zinc-300">
                        {{ $assignment->starts_on->format('Y-m-d') }} – {{ $assignment->ends_on?->format('Y-m-d') ?? __('employees.current') }}
                    </p>
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

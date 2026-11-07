<x-layouts::app :title="__('employees.title')">
    <section class="mx-auto max-w-6xl">
        <header class="mb-6">
            <h1 class="text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white">{{ __('employees.directory') }}</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ __('employees.directory_help') }}</p>
        </header>

        @if ($employees->isEmpty())
            <p class="rounded-xl border border-zinc-200 p-6 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300">{{ __('employees.empty') }}</p>
        @else
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($employees as $employee)
                        @php $assignment = $employee->assignments->first(); @endphp
                        <a href="{{ route('employees.show', $employee) }}" class="grid gap-2 p-4 hover:bg-zinc-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-teal-600 sm:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))] sm:items-center dark:hover:bg-zinc-800">
                            <span class="min-w-0">
                                <span class="block font-medium text-zinc-950 dark:text-white">{{ $employee->name }}</span>
                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $employee->employee_number }}</span>
                            </span>
                            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $assignment?->department?->name ?? __('employees.unassigned') }}</span>
                            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $assignment?->position?->name ?? __('employees.unassigned') }}</span>
                            <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ $assignment?->location?->name ?? __('employees.unassigned') }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
            <div class="mt-5">{{ $employees->links() }}</div>
        @endif
    </section>
</x-layouts::app>

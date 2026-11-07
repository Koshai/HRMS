<x-layouts::app :title="__('attendance.team')">
    <section class="mx-auto max-w-6xl space-y-6 p-6">
        <header><h1 class="text-3xl font-semibold">{{ __('attendance.team') }}</h1><p class="mt-2 text-sm">{{ __('attendance.summary') }}</p></header>
        <form method="GET" class="grid gap-3 rounded-xl border p-4 sm:grid-cols-4">
            <label>{{ __('attendance.search') }}<input name="search" value="{{ $search }}" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            <label>{{ __('attendance.from') }}<input type="date" name="from" value="{{ $from }}" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            <label>{{ __('attendance.to') }}<input type="date" name="to" value="{{ $to }}" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            <button class="self-end rounded-lg bg-teal-700 px-4 py-2 text-white">{{ __('attendance.filter') }}</button>
        </form>
        @forelse($employees as $employee)
            <a href="{{ route('attendance.show', $employee) }}" class="grid gap-2 rounded-xl border p-4 hover:bg-zinc-50 sm:grid-cols-3 dark:hover:bg-zinc-800">
                <span class="font-medium">{{ $employee->name }} <span class="text-xs text-zinc-500">{{ $employee->employee_number }}</span></span>
                <span>{{ __('attendance.check_ins') }}: {{ $summaries[$employee->id]['check_ins'] }}</span>
                <span>{{ __('attendance.check_outs') }}: {{ $summaries[$employee->id]['check_outs'] }}</span>
            </a>
        @empty <p>{{ __('attendance.no_records') }}</p> @endforelse
        {{ $employees->links() }}
    </section>
</x-layouts::app>

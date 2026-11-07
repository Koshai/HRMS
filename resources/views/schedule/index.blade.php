<x-layouts::app :title="__('schedule.team')"><section class="mx-auto max-w-5xl space-y-5 p-6"><h1 class="text-3xl font-semibold">{{ __('schedule.team') }}</h1>
@forelse($employees as $employee)<a href="{{ route('schedule.show', $employee) }}" class="block rounded-xl border p-4 hover:bg-zinc-100 dark:hover:bg-zinc-700">{{ $employee->name }} · {{ $employee->employee_number }}</a>@empty<p>{{ __('schedule.no_records') }}</p>@endforelse
{{ $employees->links() }}</section></x-layouts::app>

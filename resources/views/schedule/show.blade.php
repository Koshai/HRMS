<x-layouts::app :title="$mine ? __('schedule.mine') : $employee->name"><section class="mx-auto max-w-5xl space-y-5 p-6">
<h1 class="text-3xl font-semibold">{{ $mine ? __('schedule.mine') : $employee->name }}</h1><p>{{ $employee->employee_number }}</p>
<form method="GET" class="flex items-end gap-3"><label>{{ __('schedule.month') }}<input type="month" name="month" value="{{ $month }}" class="mt-1 block rounded border p-2 dark:bg-zinc-800"></label><button class="rounded bg-teal-700 px-4 py-2 text-white">{{ __('schedule.view') }}</button></form>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($days as $day)<article class="rounded-xl border p-4"><h2 class="font-semibold">{{ $day->schedule->date }}</h2><p class="mt-1 text-sm">{{ __('schedule.'.$day->status) }}</p>
@if($day->schedule->status === 'scheduled')<p class="mt-2">{{ $day->schedule->template->name }}</p><p class="text-sm">{{ $day->schedule->startsAt->setTimezone($day->schedule->timezone)->format('H:i') }} – {{ $day->schedule->endsAt->setTimezone($day->schedule->timezone)->format('H:i') }} @if($day->schedule->template->ends_next_day) · {{ __('schedule.overnight') }} @endif</p><p class="text-sm">{{ __('schedule.scheduled_minutes') }}: {{ $day->schedule->scheduledMinutes }}</p>@endif
@if($day->actualMinutes)<p class="text-sm">{{ __('schedule.actual_minutes') }}: {{ $day->actualMinutes }}</p>@endif
@if($day->lateMinutes)<p class="text-sm text-amber-700 dark:text-amber-300">{{ __('schedule.late_minutes') }}: {{ $day->lateMinutes }}</p>@endif
@if($day->earlyMinutes)<p class="text-sm text-amber-700 dark:text-amber-300">{{ __('schedule.early_minutes') }}: {{ $day->earlyMinutes }}</p>@endif
@if($day->schedule->timezone)<p class="mt-1 text-xs text-zinc-500">{{ $day->schedule->timezone }}</p>@endif</article>@endforeach</div>
</section></x-layouts::app>

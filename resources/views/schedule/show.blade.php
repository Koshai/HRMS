<x-layouts::app :title="$mine ? __('schedule.mine') : $employee->name"><section class="mx-auto max-w-5xl space-y-5 p-6">
<h1 class="text-3xl font-semibold">{{ $mine ? __('schedule.mine') : $employee->name }}</h1><p>{{ $employee->employee_number }}</p>
<form method="GET" class="flex items-end gap-3"><label>{{ __('schedule.month') }}<input type="month" name="month" value="{{ $month }}" class="mt-1 block rounded border p-2 dark:bg-zinc-800"></label><button class="rounded bg-teal-700 px-4 py-2 text-white">{{ __('schedule.view') }}</button></form>
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($days as $duty)<article class="rounded-xl border p-4"><h2 class="font-semibold">{{ $duty->date }}</h2><p class="mt-1 text-sm">{{ __('schedule.'.$duty->status) }}</p>
@if($duty->status === 'scheduled')<p class="mt-2">{{ $duty->template->name }}</p><p class="text-sm">{{ $duty->startsAt->setTimezone($duty->timezone)->format('H:i') }} – {{ $duty->endsAt->setTimezone($duty->timezone)->format('H:i') }} @if($duty->template->ends_next_day) · {{ __('schedule.overnight') }} @endif</p><p class="text-sm">{{ __('schedule.scheduled_minutes') }}: {{ $duty->scheduledMinutes }}</p>@endif
@if($duty->timezone)<p class="mt-1 text-xs text-zinc-500">{{ $duty->timezone }}</p>@endif</article>@endforeach</div>
</section></x-layouts::app>

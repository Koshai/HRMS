<x-layouts::app :title="__('attendance.history')">
    <section class="mx-auto max-w-5xl space-y-6 p-6">
        <header>
            @unless($mine)<a href="{{ route('attendance.index') }}" class="text-sm text-teal-700 underline dark:text-teal-300">← {{ __('attendance.back') }}</a>@endunless
            <h1 class="mt-2 text-3xl font-semibold">{{ $mine ? __('attendance.mine') : $employee->name }}</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $employee->employee_number }}</p>
        </header>
        @if(session('status'))<p role="status" class="rounded-lg bg-teal-50 p-3 text-teal-800 dark:bg-teal-950 dark:text-teal-100">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950 dark:text-red-100"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if($canPunch)
            @php $nextAction = $latest?->action === 'check_in' ? 'check_out' : 'check_in'; @endphp
            <form method="POST" action="{{ route('attendance.punch') }}" class="rounded-xl border p-5">
                @csrf <input type="hidden" name="action" value="{{ $nextAction }}"><input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <button class="rounded-lg bg-teal-700 px-5 py-3 font-medium text-white">{{ __('attendance.'.$nextAction) }}</button>
            </form>
        @endif
        @if($mine && ! $canPunch)<p class="rounded-xl border p-4 text-sm">{{ __('attendance.no_assignment') }}</p>@endif
        @can('attendance-manual-entry', $employee)
            <form method="POST" action="{{ route('attendance.manual', $employee) }}" class="grid gap-3 rounded-xl border p-5 sm:grid-cols-2">
                @csrf <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <h2 class="text-lg font-semibold sm:col-span-2">{{ __('attendance.manual') }}</h2>
                <label>{{ __('attendance.action') }}<select name="action" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"><option value="check_in">{{ __('attendance.check_in') }}</option><option value="check_out">{{ __('attendance.check_out') }}</option></select></label>
                <label>{{ __('attendance.occurred_local') }}<input type="datetime-local" name="occurred_local" required class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
                <label>{{ __('attendance.offset') }}<input name="occurred_offset" placeholder="+06:00" pattern="[+-][0-9]{2}:[0-9]{2}" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
                <label class="sm:col-span-2">{{ __('attendance.reason') }}<textarea name="reason" required maxlength="2000" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></textarea></label>
                <button class="rounded-lg bg-teal-700 px-4 py-2 text-white">{{ __('attendance.submit') }}</button>
            </form>
        @endcan
        <form method="GET" class="flex flex-wrap items-end gap-3"><label>{{ __('attendance.from') }}<input type="date" name="from" value="{{ $from }}" class="mt-1 block rounded-lg border p-2 dark:bg-zinc-800"></label><label>{{ __('attendance.to') }}<input type="date" name="to" value="{{ $to }}" class="mt-1 block rounded-lg border p-2 dark:bg-zinc-800"></label><button class="rounded-lg border px-4 py-2">{{ __('attendance.filter') }}</button></form>
        <div class="rounded-xl border p-4"><h2 class="font-semibold">{{ __('attendance.summary') }}</h2><p class="mt-2 text-sm">{{ __('attendance.check_ins') }}: {{ $effective->where('action', 'check_in')->count() }} · {{ __('attendance.check_outs') }}: {{ $effective->where('action', 'check_out')->count() }}</p>@if($latest?->action === 'check_in')<p class="mt-2 text-amber-700 dark:text-amber-300">{{ $latest->occurred_at->diffInHours(now('UTC')) >= 24 ? __('attendance.missing_checkout') : __('attendance.open') }}</p>@endif</div>
        <h2 class="text-xl font-semibold">{{ __('attendance.history') }}</h2>
        @forelse($records as $record)
            @php $state = $record->decision?->decision ?? ($record->corrections->contains(fn ($correction) => $correction->decision?->decision === 'approved') ? 'superseded' : $record->status); @endphp
            <article class="space-y-3 rounded-xl border p-5">
                <div class="flex flex-wrap justify-between gap-2"><span class="font-semibold">{{ __('attendance.'.$record->action) }} · {{ $record->occurred_at->setTimezone($record->timezone)->format('Y-m-d H:i') }}</span><span class="rounded-full bg-zinc-100 px-3 py-1 text-xs dark:bg-zinc-700">{{ __('attendance.'.$state) }}</span></div>
                <dl class="grid gap-2 text-sm sm:grid-cols-3"><div><dt class="text-zinc-500">{{ __('attendance.business_date') }}</dt><dd>{{ $record->business_date->format('Y-m-d') }}</dd></div><div><dt class="text-zinc-500">{{ __('attendance.time_zone') }}</dt><dd>{{ $record->timezone }}</dd></div><div><dt class="text-zinc-500">{{ __('attendance.actor') }}</dt><dd>{{ $record->actor->name }}</dd></div></dl>
                @if($record->correction_of_id)<p class="text-sm">{{ __('attendance.original') }} #{{ $record->correction_of_id }} · {{ $record->reason }}</p>@elseif($record->reason)<p class="text-sm">{{ $record->reason }}</p>@endif
                @if($record->decision)<p class="text-sm">{{ __('attendance.review') }}: {{ $record->decision->actor->name }} · {{ $record->decision->reason }}</p>@endif
                @if(! $record->correction_of_id)
                    @can('attendance-propose-correction', $record)
                        <details class="text-sm"><summary class="cursor-pointer text-teal-700 dark:text-teal-300">{{ __('attendance.correct') }}</summary>
                            <form method="POST" action="{{ route('attendance.correct', [$employee, $record]) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                                @csrf <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                <label>{{ __('attendance.action') }}<select name="action" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"><option value="check_in" @selected($record->action === 'check_in')>{{ __('attendance.check_in') }}</option><option value="check_out" @selected($record->action === 'check_out')>{{ __('attendance.check_out') }}</option></select></label>
                                <label>{{ __('attendance.occurred_local') }}<input type="datetime-local" name="occurred_local" value="{{ $record->occurred_at->setTimezone($record->timezone)->format('Y-m-d\TH:i') }}" required class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
                                <label>{{ __('attendance.offset') }}<input name="occurred_offset" value="{{ $record->occurred_at->setTimezone($record->timezone)->format('P') }}" pattern="[+-][0-9]{2}:[0-9]{2}" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
                                <label class="sm:col-span-2">{{ __('attendance.reason') }}<textarea name="reason" required maxlength="2000" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></textarea></label>
                                <button class="rounded-lg border px-4 py-2">{{ __('attendance.submit') }}</button>
                            </form>
                        </details>
                    @endcan
                @elseif(! $record->decision)
                    @can('attendance-decide', $record)
                        <form method="POST" action="{{ route('attendance.decide', [$employee, $record]) }}" class="grid gap-3 sm:grid-cols-3">
                            @csrf <label class="sm:col-span-2">{{ __('attendance.reason') }}<input name="reason" required maxlength="2000" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
                            <label>{{ __('attendance.review') }}<select name="decision" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"><option value="approved">{{ __('attendance.approve') }}</option><option value="rejected">{{ __('attendance.reject') }}</option></select></label>
                            <button class="rounded-lg bg-teal-700 px-4 py-2 text-white">{{ __('attendance.submit') }}</button>
                        </form>
                    @endcan
                @endif
            </article>
        @empty <p>{{ __('attendance.no_records') }}</p> @endforelse
        {{ $records->links() }}
    </section>
</x-layouts::app>

<x-layouts::app :title="__('leave.mine')">
<section class="mx-auto max-w-5xl space-y-6 p-6">
 <h1 class="text-3xl font-semibold">{{ __('leave.mine') }}</h1>
 @if(session('status'))<p role="status" class="rounded-lg bg-teal-900 p-3 text-white">{{ session('status') }}</p>@endif
 @if($errors->any())<div role="alert" class="rounded-lg bg-red-900 p-3 text-white"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
 <form method="GET" class="flex items-end gap-3"><label>{{ __('leave.year') }} <input type="number" name="year" min="2000" max="2100" value="{{ $year }}" class="block rounded border p-2 dark:bg-zinc-800"></label><button class="rounded bg-teal-700 px-4 py-2 text-white">{{ __('leave.submit') }}</button></form>
 <h2 class="text-xl font-semibold">{{ __('leave.balance') }}</h2>
 <div class="grid gap-3 md:grid-cols-2">@foreach($types as $type)<article class="rounded-xl border p-4"><h3 class="font-semibold">{{ $type->name }}</h3><dl class="mt-2 grid grid-cols-2 gap-2 text-sm"><div>{{ __('leave.granted') }}: {{ $balances[$type->id]['granted']/2 }} {{ __('leave.days') }}</div><div>{{ __('leave.used') }}: {{ $balances[$type->id]['used']/2 }} {{ __('leave.days') }}</div><div>{{ __('leave.pending') }}: {{ $balances[$type->id]['pending']/2 }} {{ __('leave.days') }}</div><div class="font-semibold">{{ __('leave.available') }}: {{ $balances[$type->id]['available']/2 }} {{ __('leave.days') }}</div></dl></article>@endforeach</div>
 @can('leave-request', $employee)<form method="POST" action="{{ route('leave.store') }}" class="grid gap-3 rounded-xl border p-5 sm:grid-cols-2">@csrf
  <h2 class="text-xl font-semibold sm:col-span-2">{{ __('leave.request') }}</h2><input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
  <label>{{ __('leave.type') }}<select required name="leave_type_id" class="mt-1 w-full rounded border p-2 dark:bg-zinc-800">@foreach($types->where('is_active', true) as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</select></label>
  <label>{{ __('leave.partial') }}<select name="partial" class="mt-1 w-full rounded border p-2 dark:bg-zinc-800"><option value="">{{ __('leave.full') }}</option><option value="am">{{ __('leave.am') }}</option><option value="pm">{{ __('leave.pm') }}</option></select></label>
  <label>{{ __('leave.start') }}<input required type="date" name="starts_on" class="mt-1 w-full rounded border p-2 dark:bg-zinc-800"></label><label>{{ __('leave.end') }}<input required type="date" name="ends_on" class="mt-1 w-full rounded border p-2 dark:bg-zinc-800"></label>
  <label class="sm:col-span-2">{{ __('leave.reason') }}<textarea name="reason" maxlength="2000" class="mt-1 w-full rounded border p-2 dark:bg-zinc-800"></textarea></label><button class="rounded bg-teal-700 px-4 py-2 text-white">{{ __('leave.submit') }}</button>
 </form>@endcan
 <h2 class="text-xl font-semibold">{{ __('leave.history') }}</h2>
 @forelse($requests as $record)<article class="rounded-xl border p-4"><div class="flex flex-wrap justify-between gap-2"><strong>{{ $record->type->name }} · {{ $record->starts_on->toDateString() }} – {{ $record->ends_on->toDateString() }}</strong><span>{{ __('leave.'.$record->status) }} · {{ $record->units/2 }} {{ __('leave.days') }}</span></div>@if($record->status === 'pending')<form method="POST" action="{{ route('leave.withdraw', $record) }}" class="mt-3 flex gap-2">@csrf<input required name="reason" maxlength="2000" placeholder="{{ __('leave.reason') }}" class="flex-1 rounded border p-2 dark:bg-zinc-800"><button class="rounded border px-3 py-2">{{ __('leave.withdraw') }}</button></form>@endif</article>@empty<p>{{ __('leave.no_records') }}</p>@endforelse
 {{ $requests->links() }}
</section></x-layouts::app>

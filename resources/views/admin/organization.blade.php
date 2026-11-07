<x-layouts::app :title="__('admin.'.$type)">
    <section class="mx-auto max-w-5xl space-y-6 p-6">
        <header><h1 class="text-3xl font-semibold">{{ __('admin.'.$type) }}</h1></header>
        @if(session('status'))<p role="status" class="rounded-lg bg-teal-50 p-3 text-teal-800 dark:bg-teal-950 dark:text-teal-100">{{ session('status') }}</p>@endif
        @if($errors->any())<div role="alert" class="rounded-lg bg-red-50 p-3 text-red-800 dark:bg-red-950 dark:text-red-100"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <nav class="flex flex-wrap gap-3" aria-label="{{ __('admin.organization') }}">
            @foreach(['departments','positions','locations'] as $section)
                <a href="{{ route('admin.organization.index', $section) }}" class="rounded-lg border px-3 py-2 hover:bg-zinc-100 dark:hover:bg-zinc-700" @if($type === $section) aria-current="page" @endif>{{ __('admin.'.$section) }}</a>
            @endforeach
        </nav>
        <form method="GET" class="flex gap-2"><label for="search" class="sr-only">{{ __('admin.search') }}</label><input id="search" name="search" value="{{ $search }}" class="w-full rounded-lg border p-2 dark:bg-zinc-800"><button class="rounded-lg bg-teal-700 px-4 py-2 text-white">{{ __('admin.search') }}</button></form>
        <form method="POST" action="{{ route('admin.organization.store', $type) }}" class="grid gap-3 rounded-xl border p-4 sm:grid-cols-3">
            @csrf
            <label>{{ __('admin.name') }}<input name="name" value="{{ old('name') }}" required maxlength="255" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
            @if($type === 'locations')<label>{{ __('admin.timezone') }}<input name="timezone" value="{{ old('timezone', 'Asia/Dhaka') }}" required class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>@endif
            <input type="hidden" name="is_active" value="1"><button class="self-end rounded-lg bg-teal-700 px-4 py-2 text-white">{{ __('admin.add') }}</button>
        </form>
        @forelse($items as $item)
            <form method="POST" action="{{ route('admin.organization.update', [$type, $item->id]) }}" class="grid gap-3 rounded-xl border p-4 sm:grid-cols-4 sm:items-end">
                @csrf @method('PUT')
                <label>{{ __('admin.name') }}<input name="name" value="{{ $item->name }}" required maxlength="255" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>
                @if($type === 'locations')<label>{{ __('admin.timezone') }}<input name="timezone" value="{{ $item->timezone }}" required class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"></label>@endif
                <label>{{ __('admin.status') }}<select name="is_active" class="mt-1 w-full rounded-lg border p-2 dark:bg-zinc-800"><option value="1" @selected($item->is_active)>{{ __('admin.active') }}</option><option value="0" @selected(! $item->is_active)>{{ __('admin.inactive') }}</option></select></label>
                <button class="rounded-lg border px-4 py-2">{{ __('admin.save') }}</button>
            </form>
        @empty <p>{{ __('admin.no_results') }}</p> @endforelse
        {{ $items->links() }}
    </section>
</x-layouts::app>

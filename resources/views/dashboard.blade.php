<x-layouts::app :title="__('dashboard.title')">
    @php
        $timezone = config('hrms.display_timezone');
        $localTime = now()->setTimezone($timezone);
    @endphp

    <section class="mx-auto max-w-6xl space-y-7">
        <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-6 sm:p-8 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mb-5 inline-flex rounded-full bg-teal-100 px-3 py-1 text-xs font-semibold text-teal-900 dark:bg-teal-950 dark:text-teal-200">
                    {{ __('portal.roles.'.auth()->user()->role->value) }}
                </div>
                <h1 class="text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white">{{ __('dashboard.title') }}</h1>
                <flux:text class="mt-2">{{ __('dashboard.welcome') }}</flux:text>
                <p class="mt-5 text-base font-medium text-zinc-800 dark:text-zinc-100">{{ auth()->user()->name }}</p>
            </div>

            <div class="rounded-2xl border border-zinc-200 p-6 sm:p-8 dark:border-zinc-700">
                <flux:icon.clock class="mb-5 size-5 text-teal-700 dark:text-teal-300" aria-hidden="true" />
                <flux:text>{{ __('dashboard.local_time', ['timezone' => $timezone]) }}</flux:text>
                <time class="mt-1 block text-2xl font-semibold tabular-nums text-zinc-950 dark:text-white" datetime="{{ $localTime->toIso8601String() }}">
                    {{ $localTime->format('H:i') }}
                </time>
                <flux:text>{{ $localTime->format('Y-m-d') }}</flux:text>
            </div>
        </div>

        <section aria-labelledby="quick-links">
            <h2 id="quick-links" class="mb-3 text-lg font-semibold text-zinc-950 dark:text-white">{{ __('portal.quick_links') }}</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (['profile' => 'profile.edit', 'security' => 'security.edit', 'guide' => 'guide'] as $shortcut => $route)
                    <a href="{{ route($route) }}" wire:navigate class="group flex items-center justify-between gap-3 rounded-xl border border-zinc-200 p-4 text-sm font-medium text-zinc-800 hover:border-teal-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-600 dark:border-zinc-700 dark:text-zinc-100 dark:hover:border-teal-400">
                        {{ __('portal.'.$shortcut) }}
                        <flux:icon.arrow-up-right class="size-4 shrink-0 text-zinc-500 group-hover:text-teal-700 dark:text-zinc-400 dark:group-hover:text-teal-300" aria-hidden="true" />
                    </a>
                @endforeach
            </div>
        </section>
    </section>
</x-layouts::app>

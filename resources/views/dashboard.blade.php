<x-layouts::app :title="__('dashboard.title')">
    <flux:main>
        <flux:heading size="xl">{{ __('dashboard.title') }}</flux:heading>
        <flux:text class="mt-2">{{ __('dashboard.welcome') }}</flux:text>

        @php
            $timezone = config('hrms.display_timezone');
            $localTime = now()->setTimezone($timezone);
        @endphp

        <flux:text class="mt-6">
            {{ __('dashboard.local_time', ['timezone' => $timezone]) }}
            <time class="block" datetime="{{ $localTime->toIso8601String() }}">
                {{ $localTime->format('Y-m-d H:i') }}
            </time>
        </flux:text>
    </flux:main>
</x-layouts::app>

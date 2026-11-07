<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => __('portal.home')])
        <meta name="description" content="{{ __('portal.intro') }}">
    </head>
    <body class="min-h-screen bg-[#f8faf7] text-zinc-950 dark:bg-zinc-950 dark:text-zinc-100">
        @php
            $accessUrl = auth()->check() ? route('dashboard') : route('login');
            $accessLabel = auth()->check() ? __('portal.open_dashboard') : __('portal.sign_in');
        @endphp

        <header class="border-b border-teal-950/10 dark:border-white/10">
            <div class="mx-auto flex min-h-20 max-w-7xl items-center justify-between gap-4 px-5 sm:px-8">
                <a href="{{ route('home') }}" class="inline-flex items-center gap-3 font-semibold">
                    <span class="grid size-9 place-items-center rounded-lg bg-teal-900 text-white dark:bg-teal-200 dark:text-teal-950">
                        <x-app-logo-icon class="size-5" aria-hidden="true" />
                    </span>
                    {{ config('app.name') }}
                </a>
                <nav class="hidden items-center gap-8 text-sm text-zinc-600 sm:flex dark:text-zinc-300">
                    <a href="#inside" class="hover:underline">{{ __('portal.inside') }}</a>
                    <a href="#access" class="hover:underline">{{ __('portal.access') }}</a>
                </nav>
                <a href="{{ $accessUrl }}" class="rounded-lg border border-teal-900/20 px-4 py-2 text-sm font-semibold text-teal-950 hover:bg-teal-100 dark:border-teal-300/40 dark:text-teal-100 dark:hover:bg-teal-950">
                    {{ $accessLabel }}
                </a>
            </div>
        </header>

        <main>
            <section class="mx-auto grid max-w-7xl items-center gap-10 px-5 py-14 sm:px-8 sm:py-20 lg:grid-cols-2 lg:gap-14 lg:py-24">
                <div>
                    <p class="text-xs font-semibold tracking-widest text-teal-800 uppercase dark:text-teal-300">{{ __('portal.intro_label') }}</p>
                    <h1 class="mt-5 max-w-xl text-5xl leading-[1.04] font-semibold tracking-tight text-teal-950 sm:text-6xl lg:text-7xl dark:text-teal-50">
                        {{ __('portal.headline') }}
                    </h1>
                    <p class="mt-6 max-w-md text-lg leading-8 text-zinc-600 dark:text-zinc-300">{{ __('portal.intro') }}</p>
                    <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-3">
                        <a href="{{ $accessUrl }}" class="inline-flex items-center gap-3 rounded-lg bg-teal-900 px-5 py-3 font-semibold text-white hover:bg-teal-800 dark:bg-teal-200 dark:text-teal-950 dark:hover:bg-teal-100">
                            {{ $accessLabel }}
                            <flux:icon.arrow-right class="size-4" aria-hidden="true" />
                        </a>
                        <a href="#inside" class="py-2 text-sm font-semibold text-teal-900 underline underline-offset-4 dark:text-teal-100">{{ __('portal.inside') }}</a>
                    </div>
                </div>

                <figure class="relative isolate overflow-hidden rounded-3xl bg-teal-950 p-5 sm:p-9">
                    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top_right,#16645b,transparent_72%)]"></div>
                    <div class="overflow-hidden rounded-xl border border-white/20 bg-[#f8faf7] text-teal-950 shadow-xl">
                        <div class="flex items-center gap-2 border-b border-teal-950/10 px-4 py-3 text-xs font-semibold sm:px-5">
                            <span class="size-2 rounded-full bg-teal-600" aria-hidden="true"></span>
                            {{ config('app.name') }}
                        </div>
                        <div class="grid sm:grid-cols-[minmax(0,8rem)_1fr]">
                            <div class="hidden space-y-3 border-e border-teal-950/10 bg-teal-50/50 p-3 text-xs font-medium text-teal-900 sm:block" aria-hidden="true">
                                <div class="rounded-md bg-teal-100 px-2 py-2">{{ __('dashboard.title') }}</div>
                                <div class="px-2">{{ __('portal.profile') }}</div>
                                <div class="px-2">{{ __('portal.security') }}</div>
                            </div>
                            <div class="p-5 sm:p-7">
                                <p class="text-xs font-medium text-teal-800">{{ __('portal.workspace') }}</p>
                                <p class="mt-2 text-2xl font-semibold tracking-tight">{{ __('dashboard.title') }}</p>
                                <div class="mt-5 flex items-center gap-3 rounded-lg border border-teal-950/10 bg-white p-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-teal-100">
                                        <flux:icon.clock class="size-5" aria-hidden="true" />
                                    </span>
                                    <div>
                                        <p class="text-sm font-semibold">{{ __('portal.bangladesh_time') }}</p>
                                        <p class="text-xs text-zinc-600">Asia/Dhaka</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <figcaption class="mt-5 text-sm text-teal-50">
                        <span class="font-semibold">{{ __('portal.preview') }}</span>
                        <span class="block text-teal-100/80">{{ __('portal.preview_note') }}</span>
                    </figcaption>
                </figure>
            </section>

            <section id="inside" class="border-y border-teal-950/10 bg-white dark:border-white/10 dark:bg-zinc-900">
                <div class="mx-auto grid max-w-7xl gap-8 px-5 py-12 sm:px-8 lg:grid-cols-[1fr_2fr] lg:gap-16">
                    <h2 class="text-3xl font-semibold tracking-tight text-teal-950 dark:text-teal-50">{{ __('portal.inside_title') }}</h2>
                    <dl class="grid gap-6 sm:grid-cols-3">
                        <div>
                            <dt class="font-semibold">{{ __('dashboard.title') }}</dt>
                            <dd class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('portal.dashboard_detail') }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('portal.profile') }}</dt>
                            <dd class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('portal.profile_detail') }}</dd>
                        </div>
                        <div>
                            <dt class="font-semibold">{{ __('portal.security') }}</dt>
                            <dd class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('portal.security_detail') }}</dd>
                        </div>
                    </dl>
                </div>
            </section>

            <section id="access" class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-6 px-5 py-12 sm:px-8">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight">{{ __('portal.access_title') }}</h2>
                    <p class="mt-2 max-w-xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">{{ __('portal.access_detail') }}</p>
                </div>
                <a href="{{ $accessUrl }}" class="rounded-lg border border-teal-900/20 px-4 py-2 text-sm font-semibold text-teal-950 hover:bg-teal-100 dark:border-teal-300/40 dark:text-teal-100 dark:hover:bg-teal-950">{{ $accessLabel }}</a>
            </section>
        </main>
    </body>
</html>

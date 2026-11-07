<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('portal.workspace')">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('dashboard.title') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="users" :href="route('employees.index')" :current="request()->routeIs('employees.*')" wire:navigate>
                        {{ __('employees.title') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="clock" :href="route('attendance.mine')" :current="request()->routeIs('attendance.mine')" wire:navigate>{{ __('attendance.mine') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="calendar-days" :href="route('schedule.mine')" :current="request()->routeIs('schedule.mine')" wire:navigate>{{ __('schedule.mine') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="calendar-days" :href="route('leave.mine')" :current="request()->routeIs('leave.mine')" wire:navigate>{{ __('leave.mine') }}</flux:sidebar.item>
                    <flux:sidebar.item icon="document-text" :href="route('payroll.mine')" :current="request()->routeIs('payroll.mine', 'payroll.payslip')" wire:navigate>{{ __('payroll.payslips') }}</flux:sidebar.item>
                    @if(in_array(auth()->user()->role, [\App\Enums\Role::Admin, \App\Enums\Role::HR, \App\Enums\Role::Manager], true))
                        <flux:sidebar.item icon="calendar-days" :href="route('attendance.index')" :current="request()->routeIs('attendance.index', 'attendance.show')" wire:navigate>{{ __('attendance.team') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('schedule.index')" :current="request()->routeIs('schedule.index', 'schedule.show')" wire:navigate>{{ __('schedule.team') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="users" :href="route('leave.index')" :current="request()->routeIs('leave.index')" wire:navigate>{{ __('leave.team') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="clipboard-document-check" :href="route('payroll.team')" :current="request()->routeIs('payroll.team')" wire:navigate>Attendance exceptions</flux:sidebar.item>
                    @endif
                    @can('manage-staff', auth()->user()->company)
                        <flux:sidebar.item icon="building-office" :href="route('admin.organization.index', 'departments')" :current="request()->routeIs('admin.organization.*')" wire:navigate>{{ __('admin.organization') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="calendar-days" :href="route('admin.leave.index')" :current="request()->routeIs('admin.leave.*')" wire:navigate>{{ __('leave.settings') }}</flux:sidebar.item>
                        <flux:sidebar.item icon="clock" :href="route('admin.schedule.index')" :current="request()->routeIs('admin.schedule.*')" wire:navigate>{{ __('schedule.settings') }}</flux:sidebar.item>
                    @endcan
                    @can('payroll-manage')
                        <flux:sidebar.item icon="banknotes" :href="route('payroll.index')" :current="request()->routeIs('payroll.*') && !request()->routeIs('payroll.mine', 'payroll.payslip')" wire:navigate>{{ __('payroll.title') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
                <flux:sidebar.group :heading="__('portal.account')">
                    <flux:sidebar.item icon="user" :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate>
                        {{ __('portal.profile') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="shield-check" :href="route('security.edit')" :current="request()->routeIs('security.edit', 'password.confirm')" wire:navigate>
                        {{ __('portal.security') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="swatch" :href="route('appearance.edit')" :current="request()->routeIs('appearance.edit')" wire:navigate>
                        {{ __('portal.appearance') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('portal.help')">
                    <flux:sidebar.item icon="book-open" :href="route('guide')" :current="request()->routeIs('guide')" wire:navigate>
                        {{ __('portal.guide') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="globe-alt" :href="route('home')">
                        {{ __('portal.home') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" :aria-label="__('portal.open_navigation')" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>

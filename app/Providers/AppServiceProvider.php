<?php

namespace App\Providers;

use App\Policies\AttendancePolicy;
use App\Policies\LeavePolicy;
use App\Policies\SchedulePolicy;
use App\Policies\UserPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Gate::define('manage-staff', [UserPolicy::class, 'manageStaff']);
        Gate::define('attendance-view', [AttendancePolicy::class, 'view']);
        Gate::define('attendance-self-punch', [AttendancePolicy::class, 'selfPunch']);
        Gate::define('attendance-manual-entry', [AttendancePolicy::class, 'manualEntry']);
        Gate::define('attendance-propose-correction', [AttendancePolicy::class, 'proposeCorrection']);
        Gate::define('attendance-decide', [AttendancePolicy::class, 'decide']);
        Gate::define('leave-configure', [LeavePolicy::class, 'configure']);
        Gate::define('leave-view', [LeavePolicy::class, 'view']);
        Gate::define('leave-request', [LeavePolicy::class, 'request']);
        Gate::define('leave-decide', [LeavePolicy::class, 'decide']);
        Gate::define('leave-cancel', [LeavePolicy::class, 'cancel']);
        Gate::define('schedule-manage', [SchedulePolicy::class, 'manage']);
        Gate::define('schedule-view', [SchedulePolicy::class, 'view']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

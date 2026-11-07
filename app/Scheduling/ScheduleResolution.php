<?php

namespace App\Scheduling;

use Carbon\CarbonImmutable;

class ScheduleResolution
{
    public function __construct(
        public readonly string $status,
        public readonly string $date,
        public readonly ?ShiftTemplate $template = null,
        public readonly ?CarbonImmutable $startsAt = null,
        public readonly ?CarbonImmutable $endsAt = null,
        public readonly ?string $timezone = null,
        public readonly int $scheduledMinutes = 0,
    ) {}
}

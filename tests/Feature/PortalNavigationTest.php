<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_the_landing_page_and_find_sign_in(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(__('portal.headline'))
            ->assertSee('href="'.route('login').'"', false);
    }

    public function test_staff_can_open_the_dashboard_from_the_landing_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee(__('portal.open_dashboard'))
            ->assertSee('href="'.route('dashboard').'"', false);
    }

    public function test_staff_guide_requires_authentication(): void
    {
        $this->get(route('guide'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('guide'))
            ->assertOk()
            ->assertSee('HRMS Portal user guide')
            ->assertSee('Find your way around');
    }
}

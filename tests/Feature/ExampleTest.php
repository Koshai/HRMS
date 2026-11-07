<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_endpoint_reports_that_the_application_boots(): void
    {
        $this->get('/up')->assertOk();
    }
}

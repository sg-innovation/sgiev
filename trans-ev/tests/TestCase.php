<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Use the built-in SabPaisa simulator unless a test configures a real (faked) gateway.
        config(['payments.sabpaisa.simulate' => true]);
        \App\Support\RoleRegistry::flush();
        \App\Support\BusinessRules::flush();
        \App\Models\Lookup::flush();
        \App\Support\SiteContent::flush();
        \App\Support\Messages::flush();
    }
}

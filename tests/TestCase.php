<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Password::defaults() checks Have I Been Pwned; never call it from tests. An empty range
        // means "not breached", and a test that needs a breached password fakes its own.
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
    }
}

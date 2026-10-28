<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Base test case
//  Location: tests/TestCase.php
//
//  Every automated test extends this. Run them all with:
//      php artisan test
//  They use a temporary in-memory database (phpunit.xml), so your real
//  data is never touched and no extra database is needed.
//
//  withoutVite() → tests check the server, not the built screens
// ══════════════════════════════════════════════════════════════════

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}

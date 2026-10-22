<?php

namespace Tests;

use App\Contracts\ReverseGeocoder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeReverseGeocoder;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests never need built assets or a real geocoder, and never write real files.
        $this->withoutVite();
        $this->app->instance(ReverseGeocoder::class, new FakeReverseGeocoder);
        Storage::fake((string) config('attendance.photo_disk'));

        // The payroll worked examples (₱7,957.25, 13th month, adjustments…) were
        // written with a ₱610 field / ₱900 office company default. The real
        // config has ₱0 from 2026 (a new hire has no rate until Accounting sets
        // one); tests of that behaviour set it back with zeroCompanyDefault().
        config(['payroll.schedules.2026-01-01.daily_rate' => ['field' => 610, 'office' => 900]]);
    }
}

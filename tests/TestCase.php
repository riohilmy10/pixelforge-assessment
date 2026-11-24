<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Inertia pages are rendered through the root Blade view. Disable Vite so the
        // backend suite does not depend on a freshly built manifest for every new page.
        $this->withoutVite();

        // SSR is a production rendering path. Tests should never post pages to a Node
        // SSR server or depend on a local `build:ssr` bundle existing.
        config(['inertia.ssr.enabled' => false]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}

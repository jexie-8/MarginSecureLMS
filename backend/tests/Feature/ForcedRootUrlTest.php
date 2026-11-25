<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Behind a proxy or tunnel the request host is internal. With the setting on,
 * generated links use the public APP_URL instead.
 */
class ForcedRootUrlTest extends TestCase
{
    public function test_generated_links_use_the_public_address_when_enabled(): void
    {
        config(['margin.force_root_url' => true, 'app.url' => 'https://lms.example.test']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('https://lms.example.test/api/v1/x', url('/api/v1/x'));
    }

    public function test_links_follow_the_request_when_disabled(): void
    {
        URL::forceRootUrl(null);
        config(['margin.force_root_url' => false, 'app.url' => 'https://lms.example.test']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringNotContainsString('lms.example.test', url('/api/v1/x'));
    }
}

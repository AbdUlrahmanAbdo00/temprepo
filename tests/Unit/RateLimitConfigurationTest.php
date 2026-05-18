<?php

namespace Tests\Unit;

use App\Providers\RouteServiceProvider;
use ReflectionMethod;
use Tests\TestCase;

class RateLimitConfigurationTest extends TestCase
{
    public function test_resolve_rate_limit_respects_share_and_minimum(): void
    {
        $provider = new RouteServiceProvider(app());
        $method = new ReflectionMethod($provider, 'resolveRateLimit');
        $method->setAccessible(true);

        $this->assertSame(3857, $method->invoke($provider, 19286, 0.20, 1500));
        $this->assertSame(1500, $method->invoke($provider, 1000, 0.20, 1500));
        $this->assertSame(2500, $method->invoke($provider, 5000, 0.35, 2500));
    }
}
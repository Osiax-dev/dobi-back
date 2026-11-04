<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_api_responses_carry_security_headers(): void
    {
        $res = $this->getJson('/api/v1/ping')->assertOk();

        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('Referrer-Policy', 'no-referrer');
        $res->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }

    public function test_errors_also_carry_security_headers(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_only_on_https(): void
    {
        $this->getJson('/api/v1/ping')->assertHeaderMissing('Strict-Transport-Security');
        $this->getJson('https://localhost/api/v1/ping')->assertHeader('Strict-Transport-Security');
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_local_tunnel_forwarding_generates_https_login_and_asset_urls(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost:8000',
            'HTTP_X_FORWARDED_HOST' => 'school.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('/login')->assertOk()
            ->assertSee('action="https://school.example.test/login"', false)
            ->assertSee('src="https://school.example.test/images/school-logo.png"', false);
    }

    public function test_direct_clients_cannot_override_host_or_scheme_through_proxy_headers(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.0.2.42',
            'HTTP_HOST' => 'localhost:8000',
            'HTTP_X_FORWARDED_HOST' => 'untrusted.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '443',
        ])->get('/login')->assertOk()
            ->assertSee('action="http://localhost:8000/login"', false)
            ->assertDontSee('untrusted.example.test');
    }
}

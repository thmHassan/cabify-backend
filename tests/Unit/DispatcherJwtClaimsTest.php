<?php

namespace Tests\Unit;

use App\Models\Dispatcher;
use Tests\TestCase;

class DispatcherJwtClaimsTest extends TestCase
{
    public function test_jwt_custom_claims_include_tenant_id_when_provided(): void
    {
        $dispatcher = (new Dispatcher())->withJwtTenantId('testcompany134');

        $this->assertSame([
            'auth_version' => 0,
            'tenant_id' => 'testcompany134',
        ], $dispatcher->getJWTCustomClaims());
    }
}

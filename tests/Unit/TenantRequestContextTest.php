<?php

namespace Tests\Unit;

use App\Support\TenantRequestContext;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantRequestContextTest extends TestCase
{
    public function test_database_id_from_header(): void
    {
        $request = Request::create('/api/company/mapify-tiles/bright/1/2/3.png', 'GET');
        $request->headers->set('database', 'alpha31');

        $this->assertSame('alpha31', TenantRequestContext::databaseId($request));
    }

    public function test_database_id_from_query_string(): void
    {
        $request = Request::create('/api/company/mapify-tiles/bright/1/2/3.png?database=alpha31', 'GET');

        $this->assertSame('alpha31', TenantRequestContext::databaseId($request));
    }

    public function test_bearer_token_from_query_string(): void
    {
        $request = Request::create('/api/company/mapify-tiles/bright/1/2/3.png?token=abc123', 'GET');

        $this->assertSame('abc123', TenantRequestContext::bearerToken($request));
    }

    public function test_header_takes_precedence_over_query(): void
    {
        $request = Request::create('/api/company/mapify-tiles/bright/1/2/3.png?database=from-query', 'GET');
        $request->headers->set('database', 'from-header');

        $this->assertSame('from-header', TenantRequestContext::databaseId($request));
    }

    public function test_rate_limit_key_uses_database_and_token_when_available(): void
    {
        $request = Request::create('/api/company/mapify-tiles/bright/1/2/3.png?database=alpha31&token=abc123', 'GET');

        $this->assertSame('tenant:alpha31:' . sha1('abc123'), TenantRequestContext::rateLimitKey($request));
    }

    public function test_central_tenant_id_normalizes_schema_name(): void
    {
        $request = Request::create('/api/company/get-api-keys', 'GET');
        $request->headers->set('database', 'tenant_alpha31');

        $this->assertSame('alpha31', TenantRequestContext::centralTenantId($request));
    }
}

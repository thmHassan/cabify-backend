<?php

namespace Tests\Unit;

use App\Support\TenantDatabaseConfigurator;
use PHPUnit\Framework\TestCase;

class TenantDatabaseConfiguratorTest extends TestCase
{
    public function test_extract_tenant_id_from_plain_id(): void
    {
        $this->assertSame('cabify1', TenantDatabaseConfigurator::extractTenantId('cabify1'));
    }

    public function test_extract_tenant_id_from_underscore_schema(): void
    {
        $this->assertSame('cabify1', TenantDatabaseConfigurator::extractTenantId('tenant_cabify1'));
    }

    public function test_extract_tenant_id_from_legacy_schema(): void
    {
        $this->assertSame('cabify1', TenantDatabaseConfigurator::extractTenantId('tenantcabify1'));
    }
}

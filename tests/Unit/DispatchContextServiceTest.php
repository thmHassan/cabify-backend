<?php

namespace Tests\Unit;

use App\Services\DispatchContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DispatchContextServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.tenant', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('tenant');
        DB::connection('tenant')->getPdo();

        Schema::connection('tenant')->create('dispatch_system', function (Blueprint $table) {
            $table->id();
            $table->string('dispatch_system');
            $table->integer('priority')->nullable();
            $table->string('steps')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('tenant')->dropIfExists('dispatch_system');
        DB::purge('tenant');

        parent::tearDown();
    }

    public function test_it_returns_the_common_dispatch_context_shape(): void
    {
        DB::connection('tenant')->table('dispatch_system')->insert([
            [
                'dispatch_system' => 'auto_dispatch_plot_base',
                'priority' => 1,
                'steps' => 'send_to_ranked_driver',
                'status' => 'enable',
            ],
            [
                'dispatch_system' => 'bidding',
                'priority' => 2,
                'steps' => 'put_in_bidding_panel',
                'status' => 'enable',
            ],
        ]);

        $context = (new DispatchContextService())->resolve('auto_dispatch');

        $this->assertSame([
            'dispatch_system',
            'supports_rank',
            'supports_bidding',
            'fallback_to_bidding',
            'supports_manual_assignment',
            'allow_asap',
            'allow_scheduled',
        ], array_keys($context));
        $this->assertSame('auto_dispatch_plot_base', $context['dispatch_system']);
        $this->assertTrue($context['supports_rank']);
        $this->assertTrue($context['supports_bidding']);
        $this->assertTrue($context['allow_asap']);
        $this->assertTrue($context['allow_scheduled']);
    }

    public function test_manual_dispatch_disables_asap_in_the_common_context(): void
    {
        DB::connection('tenant')->table('dispatch_system')->insert([
            'dispatch_system' => 'manual_dispatch_only',
            'priority' => 1,
            'status' => 'enable',
        ]);

        $context = (new DispatchContextService())->resolve('auto_dispatch');

        $this->assertSame('manual_dispatch_only', $context['dispatch_system']);
        $this->assertFalse($context['allow_asap']);
        $this->assertTrue($context['allow_scheduled']);
    }
}

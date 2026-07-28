<?php

namespace Tests\Unit;

use App\Models\CompanyDriver;
use App\Models\DriverDocument;
use App\Services\DriverDocumentExpiryService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DriverDocumentExpiryServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->nullable();
            $table->string('online_status')->nullable();
            $table->timestamp('document_expiry_blocked_at')->nullable();
            $table->string('status_before_document_expiry_block')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('drivers_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('document_name')->nullable();
            $table->string('has_expiry_date')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('expiry_reminder_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_name')->nullable();
            $table->string('has_expiry_date')->default('no');
            $table->timestamp('expiry_requirement_enabled_at')->nullable();
            $table->unsignedSmallInteger('expiry_grace_days')->default(7);
            $table->timestamps();
        });

        DB::table('documents')->insert([
            [
                'id' => 10,
                'document_name' => 'Driver card',
                'has_expiry_date' => 'yes',
                'expiry_requirement_enabled_at' => '2026-07-23 09:00:00',
                'expiry_grace_days' => 7,
            ],
            [
                'id' => 20,
                'document_name' => 'Non-expiring card',
                'has_expiry_date' => 'no',
                'expiry_requirement_enabled_at' => null,
                'expiry_grace_days' => 7,
            ],
        ]);

        Carbon::setTestNow('2026-07-23 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('documents');
        Schema::dropIfExists('drivers_documents');
        Schema::dropIfExists('drivers');

        parent::tearDown();
    }

    public function test_expired_verified_document_blocks_driver_and_valid_replacement_restores_status(): void
    {
        $driver = CompanyDriver::forceCreate([
            'status' => 'accepted',
            'online_status' => 'online',
        ]);

        DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'document_id' => 10,
            'document_name' => 'Driver card',
            'has_expiry_date' => '2026-07-22',
            'status' => 'verified',
        ]);

        $service = app(DriverDocumentExpiryService::class);
        $expired = $service->syncRestriction($driver);
        $driver->refresh();

        $this->assertCount(1, $expired);
        $this->assertSame('blocked', $driver->status);
        $this->assertSame('accepted', $driver->status_before_document_expiry_block);
        $this->assertSame('offline', $driver->online_status);
        $this->assertNotNull($driver->document_expiry_blocked_at);
        $this->assertTrue($service->restrictionPayload($expired)['contact_company']);

        DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'document_id' => 10,
            'document_name' => 'Driver card',
            'has_expiry_date' => '2027-07-22',
            'status' => 'pending',
        ]);

        $this->assertCount(1, $service->syncRestriction($driver->fresh()));

        DriverDocument::where('status', 'pending')->update(['status' => 'verified']);
        $this->assertCount(0, $service->syncRestriction($driver->fresh()));

        $driver->refresh();
        $this->assertSame('accepted', $driver->status);
        $this->assertNull($driver->document_expiry_blocked_at);
        $this->assertNull($driver->status_before_document_expiry_block);
    }

    public function test_latest_verified_document_is_used_for_each_document_type(): void
    {
        $driver = CompanyDriver::forceCreate(['status' => 'accepted']);

        DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'document_id' => 10,
            'document_name' => 'Driver card',
            'has_expiry_date' => '2026-07-22',
            'status' => 'verified',
        ]);
        DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'document_id' => 10,
            'document_name' => 'Driver card',
            'has_expiry_date' => '2027-07-22',
            'status' => 'verified',
        ]);

        $expired = app(DriverDocumentExpiryService::class)->complianceIssues($driver);

        $this->assertCount(0, $expired);
    }

    public function test_document_type_without_expiry_enabled_is_ignored(): void
    {
        $driver = CompanyDriver::forceCreate(['status' => 'accepted']);

        DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'document_id' => 20,
            'document_name' => 'Non-expiring card',
            'has_expiry_date' => '2026-07-22',
            'status' => 'verified',
        ]);

        $expired = app(DriverDocumentExpiryService::class)->syncRestriction($driver);

        $this->assertCount(0, $expired);
        $this->assertSame('accepted', $driver->fresh()->status);
    }

    public function test_existing_driver_gets_seven_day_grace_for_new_requirement(): void
    {
        $driver = CompanyDriver::forceCreate([
            'status' => 'accepted',
            'created_at' => '2026-07-01 09:00:00',
            'updated_at' => '2026-07-01 09:00:00',
        ]);

        DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'document_id' => 10,
            'document_name' => 'Driver card',
            'has_expiry_date' => '2027-07-31',
            'status' => 'verified',
        ]);

        DB::table('documents')->insert([
            'id' => 30,
            'document_name' => 'New permit',
            'has_expiry_date' => 'yes',
            'expiry_requirement_enabled_at' => '2026-07-23 09:00:00',
            'expiry_grace_days' => 7,
        ]);

        $service = app(DriverDocumentExpiryService::class);

        $this->assertCount(0, $service->syncRestriction($driver, Carbon::parse('2026-07-30')));
        $this->assertSame('accepted', $driver->fresh()->status);

        $issues = $service->syncRestriction($driver->fresh(), Carbon::parse('2026-07-31'));
        $this->assertCount(1, $issues);
        $this->assertSame('missing_required_document', $issues->first()['issue_type']);
        $this->assertSame('blocked', $driver->fresh()->status);

        DriverDocument::forceCreate([
            'driver_id' => $driver->id,
            'document_id' => 30,
            'document_name' => 'New permit',
            'has_expiry_date' => '2027-07-31',
            'status' => 'verified',
        ]);

        $this->assertCount(0, $service->syncRestriction(
            $driver->fresh(),
            Carbon::parse('2026-07-31')
        ));
        $this->assertSame('accepted', $driver->fresh()->status);
    }
}

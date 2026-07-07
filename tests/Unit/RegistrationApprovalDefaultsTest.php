<?php

namespace Tests\Unit;

use App\Http\Controllers\Company\UserController;
use App\Http\Controllers\Rider\AuthController as RiderAuthController;
use App\Models\CompanyRider;
use App\Models\CompanyUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegistrationApprovalDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('database.connections.central', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::purge('central');
        DB::connection('sqlite')->getPdo();
        DB::connection('central')->getPdo();

        $this->createTenantTables();
        $this->createCompanyTables();

        DB::connection('central')->table('tenants')->insert([
            'id' => 'testcompany',
            'data' => json_encode([
                'passengers_allowed' => 100,
                'drivers_allowed' => 100,
            ]),
        ]);
        DB::connection('central')->table('settings')->insert(['id' => 1]);
        DB::table('settings')->insert(['id' => 1, 'map_settings' => 'default']);

        Http::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('users');
        Schema::connection('central')->dropIfExists('settings');
        Schema::connection('central')->dropIfExists('tenants');

        parent::tearDown();
    }

    public function test_rider_registration_auto_approves_customer_account(): void
    {
        $request = Request::create('/api/rider/register', 'POST', [
            'email' => 'customer@example.test',
            'phone' => '5550001',
            'name' => 'Customer One',
            'country_code' => '+1',
            'password' => 'secret123',
        ]);
        $request->headers->set('database', 'testcompany');

        $response = (new RiderAuthController())->register($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['success']);
        $this->assertSame(
            'active',
            CompanyRider::where('email', 'customer@example.test')->value('status')
        );
    }

    public function test_company_created_customer_account_is_active_by_default(): void
    {
        $request = Request::create('/api/company/create-user', 'POST', [
            'name' => 'Company Customer',
            'email' => 'company-customer@example.test',
            'phone_no' => '5550002',
            'country_code' => '+1',
            'address' => '1 Test Street',
            'city' => 'Test City',
            'password' => 'secret123',
        ]);
        $request->headers->set('database', 'testcompany');

        $response = (new UserController())->createUser($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['success']);
        $this->assertSame(
            'active',
            CompanyUser::where('email', 'company-customer@example.test')->value('status')
        );
    }

    public function test_driver_registration_approval_defaults_remain_pending(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Driver/AuthController.php'));

        $this->assertStringContainsString("\$driver->status = 'pending';", $source);
        $this->assertStringContainsString("\$driverDocument->status = 'pending';", $source);
        $this->assertStringContainsString("'profileStatus' => 'pending'", $source);
        $this->assertStringContainsString("'accountStatus' => 'pending'", $source);
        $this->assertStringContainsString("'verificationStatus' => 'pending'", $source);
    }

    private function createTenantTables(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->json('data')->nullable();
            $table->timestamps();
        });

        Schema::connection('central')->create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('smtp_host')->nullable();
            $table->string('smtp_user_name')->nullable();
            $table->string('smtp_password')->nullable();
            $table->string('smtp_from_address')->nullable();
            $table->timestamps();
        });
    }

    private function createCompanyTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('phone_no')->nullable();
            $table->string('country_code')->nullable();
            $table->string('password')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->unsignedBigInteger('dispatcher_id')->nullable();
            $table->string('status')->nullable();
            $table->string('otp')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['phone_no', 'country_code']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('map_settings')->nullable();
            $table->string('mail_server')->nullable();
            $table->string('mail_port')->nullable();
            $table->string('mail_user_name')->nullable();
            $table->string('mail_password')->nullable();
            $table->string('mail_from')->nullable();
            $table->timestamps();
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('onboarding_requests', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('company_admin_name')->nullable();
            $table->string('user_name')->nullable();
            $table->string('company_id')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('currency')->nullable();
            $table->string('maps_api')->nullable();
            $table->string('search_api')->nullable();
            $table->string('log_map_search_result')->nullable();
            $table->string('voip')->nullable();
            $table->string('drivers_allowed')->nullable();
            $table->string('sub_company')->nullable();
            $table->string('passengers_allowed')->nullable();
            $table->string('uber_plot_hybrid')->nullable();
            $table->string('dispatchers_allowed')->nullable();
            $table->string('subscription_type')->nullable();
            $table->string('fleet_management')->nullable();
            $table->string('sos_features')->nullable();
            $table->string('notes')->nullable();
            $table->string('stripe_enable')->nullable();
            $table->string('stripe_enablement')->nullable();
            $table->string('units')->nullable();
            $table->string('country_of_use')->nullable();
            $table->string('time_zone')->nullable();
            $table->string('enable_smtp')->nullable();
            $table->string('dispatcher')->nullable();
            $table->string('map')->nullable();
            $table->string('push_notification')->nullable();
            $table->string('usage_monitoring')->nullable();
            $table->string('revenue_statements')->nullable();
            $table->string('zone')->nullable();
            $table->string('manage_zones')->nullable();
            $table->string('cms')->nullable();
            $table->string('lost_found')->nullable();
            $table->string('accounts')->nullable();
            $table->enum('status',['pending', 'rejected', 'approved'])->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_requests');
    }
};

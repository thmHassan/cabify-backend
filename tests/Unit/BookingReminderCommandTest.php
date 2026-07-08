<?php

namespace Tests\Unit;

use App\Console\Commands\SendDueBookingReminders;
use App\Services\BookingReminderService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingReminderCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-08 10:00:00', 'UTC'));
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('services.node_socket.url', 'https://socket.test');
        Config::set('services.node_socket.internal_secret', 'secret');
        DB::purge('sqlite');
        DB::connection('sqlite')->getPdo();

        Schema::dropIfExists('bookings');
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_id')->nullable();
            $table->string('booking_status')->default('pending');
            $table->date('booking_date')->nullable();
            $table->string('pickup_time')->nullable();
            $table->boolean('is_scheduled')->default(false);
            $table->unsignedTinyInteger('reminder_minutes')->nullable();
            $table->dateTime('reminder_sent_at')->nullable();
            $table->unsignedBigInteger('driver')->nullable();
            $table->string('pickup_location')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('bookings');
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_due_reminder_is_sent_once_without_advancing_booking_status(): void
    {
        Http::fake([
            'socket.test/send-reminder' => Http::response(['success' => 1], 200),
        ]);

        $dueId = $this->insertBooking([
            'booking_id' => 'DUE-1',
            'pickup_time' => '10:15:00',
            'reminder_minutes' => 15,
            'driver' => 7,
        ]);
        $futureId = $this->insertBooking([
            'booking_id' => 'FUTURE-1',
            'pickup_time' => '10:30:00',
            'reminder_minutes' => 15,
            'driver' => 8,
        ]);

        $command = new SendDueBookingReminders();
        $sent = $command->sendDueForCurrentTenant('tenant_a', app(BookingReminderService::class));

        $this->assertSame(1, $sent);
        $this->assertSame('pending', DB::table('bookings')->where('id', $dueId)->value('booking_status'));
        $this->assertNotNull(DB::table('bookings')->where('id', $dueId)->value('reminder_sent_at'));
        $this->assertNull(DB::table('bookings')->where('id', $futureId)->value('reminder_sent_at'));

        $sentAgain = $command->sendDueForCurrentTenant('tenant_a', app(BookingReminderService::class));

        $this->assertSame(0, $sentAgain);
        Http::assertSentCount(1);
    }

    public function test_cancelled_completed_and_no_show_bookings_do_not_send_reminders(): void
    {
        Http::fake([
            'socket.test/send-reminder' => Http::response(['success' => 1], 200),
        ]);

        foreach (['cancelled', 'completed', 'no_show'] as $status) {
            $this->insertBooking([
                'booking_id' => strtoupper($status),
                'booking_status' => $status,
                'pickup_time' => '10:15:00',
                'reminder_minutes' => 15,
                'driver' => 7,
            ]);
        }

        $sent = (new SendDueBookingReminders())
            ->sendDueForCurrentTenant('tenant_a', app(BookingReminderService::class));

        $this->assertSame(0, $sent);
        Http::assertNothingSent();
    }

    private function insertBooking(array $overrides = []): int
    {
        return DB::table('bookings')->insertGetId(array_merge([
            'booking_id' => 'RD' . uniqid(),
            'booking_status' => 'pending',
            'booking_date' => '2026-07-08',
            'pickup_time' => '10:15:00',
            'is_scheduled' => true,
            'reminder_minutes' => 15,
            'reminder_sent_at' => null,
            'driver' => null,
            'pickup_location' => 'QA Pickup',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}

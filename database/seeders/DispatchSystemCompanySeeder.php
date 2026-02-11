<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;

class DispatchSystemCompanySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenants = Tenant::get();

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);
            foreach ($this->dispatchData() as $row) {
                $query = DB::table('dispatch_system')
                    ->where('dispatch_system', $row['dispatch_system']);

                if (is_null($row['steps'])) {
                    $query->whereNull('steps');
                } else {
                    $query->where('steps', $row['steps']);
                }

                $exists = $query->first();

                if ($exists) {
                    DB::table('dispatch_system')
                        ->where('id', $exists->id)
                        ->update([
                            'priority' => $row['priority'],
                            'sub_priority' => $row['sub_priority'],
                            'status' => $row['status'],
                        ]);
                } else {
                    DB::table('dispatch_system')->insert($row);
                }
            }
            tenancy()->end();
        }
    }

    private function dispatchData(): array
    {
        return [
            [
                'dispatch_system' => 'auto_dispatch_plot_base',
                'priority' => '1',
                'steps' => "immediately_show_on_dispatcher_panel",
                'sub_priority' => "1",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_plot_base',
                'priority' => '1',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_first_try",
                'sub_priority' => "2",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_plot_base',
                'priority' => '1',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_second_try",
                'sub_priority' => "3",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_plot_base',
                'priority' => '1',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_third_try",
                'sub_priority' => "4",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_plot_base',
                'priority' => '1',
                'steps' => "put_in_bidding_panel",
                'sub_priority' => "5",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding_fixed_fare_plot_base',
                'priority' => '2',
                'steps' => "wait_time_seconds",
                'sub_priority' => "1",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding_fixed_fare_plot_base',
                'priority' => '2',
                'steps' => "immediately_show_on_dispatcher_panel",
                'sub_priority' => "2",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding_fixed_fare_plot_base',
                'priority' => '2',
                'steps' => "shows_up_after_first_rejection_or_wait_time_elapsed",
                'sub_priority' => "3",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_nearest_driver',
                'priority' => '3',
                'steps' => "immediately_show_on_dispatcher_panel",
                'sub_priority' => "1",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_nearest_driver',
                'priority' => '3',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_first_try",
                'sub_priority' => "2",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_nearest_driver',
                'priority' => '3',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_second_try",
                'sub_priority' => "3",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_nearest_driver',
                'priority' => '3',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_third_try",
                'sub_priority' => "4",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'auto_dispatch_nearest_driver',
                'priority' => '3',
                'steps' => "put_in_bidding_panel",
                'sub_priority' => "5",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'manual_dispatch_only',
                'priority' => '4',
                'steps' => 'manual_only',
                'sub_priority' => NULL,
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding',
                'priority' => '5',
                'steps' => "immediately_show_on_dispatcher_panel",
                'sub_priority' => "1",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding',
                'priority' => '5',
                'steps' => "if_not_received_bid_in_first_10_seconds",
                'sub_priority' => "2",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding',
                'priority' => '5',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_first_try",
                'sub_priority' => "3",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding',
                'priority' => '5',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_second_try",
                'sub_priority' => "4",
                'status' => "disable",
            ],
            [
                'dispatch_system' => 'bidding',
                'priority' => '5',
                'steps' => "show_only_after_not_selected_in_auto_dispatch_third_try",
                'sub_priority' => "5",
                'status' => "disable",
            ],
        ];
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Schedule;
use App\Models\Organisation;

class ScheduleSeeder extends Seeder
{
    public function run()
    {

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        // 1. Regular Shift
        $lunchBrkId = 'brk_lunch_' . time();
        $teaBrkId = 'brk_tea_' . time();
        $regularDays = [];
        foreach ($days as $day) {
            $isWorking = !in_array($day, ['sunday']); // Monday to Saturday
            $regularDays[$day] = [
                'is_working' => $isWorking,
                'start' => '09:00',
                'end' => '18:00',
                'breaks' => []
            ];
            if ($isWorking) {
                $regularDays[$day]['breaks'][$lunchBrkId] = ['is_active' => true, 'start' => '13:00', 'duration' => 60, 'duration_unit' => 'minutes'];
                $regularDays[$day]['breaks'][$teaBrkId] = ['is_active' => true, 'start' => '16:00', 'duration' => 15, 'duration_unit' => 'minutes'];
            }
        }
        Schedule::updateOrCreate(
            ['name' => 'Regular Shift'],
            [
                'type' => 'regular',
                'status' => true,
                'rules' => [
                    'weekly' => [
                        'breaks' => [
                            ['id' => $lunchBrkId, 'name' => 'Lunch Break', 'duration' => 60, 'duration_unit' => 'minutes', 'start' => '13:00'],
                            ['id' => $teaBrkId, 'name' => 'Tea Break', 'duration' => 15, 'duration_unit' => 'minutes', 'start' => '16:00'],
                        ],
                        'days' => $regularDays
                    ]
                ]
            ]
        );

        // 2. Night Shift (10 PM - 6 AM)
        $night10LunchId = 'brk_n10_lunch_' . time();
        $night10TeaId = 'brk_n10_tea_' . time();
        $night10Days = [];
        foreach ($days as $day) {
            $isWorking = !in_array($day, ['sunday']);
            $night10Days[$day] = [
                'is_working' => $isWorking,
                'start' => '22:00',
                'end' => '06:00',
                'breaks' => []
            ];
            if ($isWorking) {
                $night10Days[$day]['breaks'][$night10LunchId] = ['is_active' => true, 'start' => '02:00', 'duration' => 60, 'duration_unit' => 'minutes'];
                $night10Days[$day]['breaks'][$night10TeaId] = ['is_active' => true, 'start' => '05:00', 'duration' => 15, 'duration_unit' => 'minutes'];
            }
        }
        Schedule::updateOrCreate(
            ['name' => 'Night Shift (10 PM - 6 AM)'],
            [
                'type' => 'regular',
                'status' => true,
                'rules' => [
                    'weekly' => [
                        'breaks' => [
                            ['id' => $night10LunchId, 'name' => 'Midnight Meal', 'duration' => 60, 'duration_unit' => 'minutes', 'start' => '02:00'],
                            ['id' => $night10TeaId, 'name' => 'Morning Tea', 'duration' => 15, 'duration_unit' => 'minutes', 'start' => '05:00'],
                        ],
                        'days' => $night10Days
                    ]
                ]
            ]
        );

        // 3. Night Shift (7 PM - 7 AM)
        $night7LunchId = 'brk_n7_lunch_' . time();
        $night7Tea1Id = 'brk_n7_tea1_' . time();
        $night7Tea2Id = 'brk_n7_tea2_' . time();
        $night7Days = [];
        foreach ($days as $day) {
            $isWorking = !in_array($day, ['sunday']);
            $night7Days[$day] = [
                'is_working' => $isWorking,
                'start' => '19:00',
                'end' => '07:00',
                'breaks' => []
            ];
            if ($isWorking) {
                $night7Days[$day]['breaks'][$night7Tea1Id] = ['is_active' => true, 'start' => '22:00', 'duration' => 15, 'duration_unit' => 'minutes'];
                $night7Days[$day]['breaks'][$night7LunchId] = ['is_active' => true, 'start' => '01:00', 'duration' => 60, 'duration_unit' => 'minutes'];
                $night7Days[$day]['breaks'][$night7Tea2Id] = ['is_active' => true, 'start' => '05:00', 'duration' => 15, 'duration_unit' => 'minutes'];
            }
        }
        Schedule::updateOrCreate(
            ['name' => 'Night Shift (7 PM - 7 AM)'],
            [
                'type' => 'regular',
                'status' => true,
                'rules' => [
                    'weekly' => [
                        'breaks' => [
                            ['id' => $night7Tea1Id, 'name' => 'Evening Tea', 'duration' => 15, 'duration_unit' => 'minutes', 'start' => '22:00'],
                            ['id' => $night7LunchId, 'name' => 'Midnight Meal', 'duration' => 60, 'duration_unit' => 'minutes', 'start' => '01:00'],
                            ['id' => $night7Tea2Id, 'name' => 'Morning Tea', 'duration' => 15, 'duration_unit' => 'minutes', 'start' => '05:00'],
                        ],
                        'days' => $night7Days
                    ]
                ]
            ]
        );

        echo "Default schedules created successfully.\n";
    }
}

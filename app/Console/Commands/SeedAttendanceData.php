<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Models\User;
use App\Models\AttendanceLog;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class SeedAttendanceData extends Command
{
    protected $signature = 'seed:attendance';
    protected $description = 'Seed dummy attendance data for the last 3 months';

    public function handle()
    {
        $this->info('Starting attendance data generation...');

        // 1. Create 2 devices
        $devices = [];
        foreach (['DEV_ZK_001', 'DEV_ZK_002'] as $index => $sn) {
            $devices[] = Device::firstOrCreate(
                ['serial_number' => $sn],
                [
                    'name' => 'Main Gate ' . ($index + 1),
                    'status' => 'online',
                    'last_activity_at' => now(),
                    'branch_id' => 1,
                ]
            );
        }
        $this->info('Created 2 devices.');

        // 2. Get all users with PINs
        $users = User::whereNotNull('pin')->get();
        if ($users->isEmpty()) {
            $this->error('No users found with a PIN!');
            return;
        }

        $this->info('Found ' . $users->count() . ' users.');

        // 3. Generate data for 3 months
        $startDate = Carbon::now()->subMonths(3)->startOfMonth();
        $endDate = Carbon::now();
        $period = CarbonPeriod::create($startDate, $endDate);

        $totalLogs = 0;

        // Clean up old logs to prevent duplicates
        AttendanceLog::truncate();

        foreach ($period as $date) {
            // Skip weekends
            if ($date->isWeekend()) {
                continue;
            }

            foreach ($users as $user) {
                // Randomize attendance status
                $rand = rand(1, 100);
                
                if ($rand <= 10) {
                    // 10% chance of Absent (do nothing)
                    continue;
                }

                $device = $devices[array_rand($devices)];
                $verifyType = collect([0, 1, 1, 1, 2, 15])->random(); // Mostly fingerprint (1)

                if ($rand > 10 && $rand <= 30) {
                    // 20% chance of Half Day / Late
                    // Late In: 9:30 AM to 11:30 AM
                    $inTime = $date->copy()->setTime(rand(9, 11), rand(30, 59));
                    // Out: 5:00 PM to 6:00 PM
                    $outTime = $date->copy()->setTime(rand(17, 18), rand(0, 30));
                } else {
                    // 70% chance of Present (Normal)
                    // Normal In: 8:40 AM to 9:05 AM
                    $inTime = $date->copy()->setTime(8, rand(40, 59))->addMinutes(rand(0, 25));
                    // Normal Out: 5:00 PM to 6:30 PM
                    $outTime = $date->copy()->setTime(17, rand(0, 59))->addMinutes(rand(0, 30));
                }

                // Generate 6 punches
                $punches = [
                    $inTime,
                    $inTime->copy()->addMinutes(rand(120, 150)),
                    $inTime->copy()->addMinutes(rand(180, 210)),
                    $inTime->copy()->addMinutes(rand(240, 270)),
                    $inTime->copy()->addMinutes(rand(300, 330)),
                    $outTime
                ];

                $statuses = [0, 2, 3, 2, 3, 1]; // In, Break Out, Break In, Break Out, Break In, Out

                foreach ($punches as $index => $punchTime) {
                    // Make sure punches don't exceed outTime (except for the last one)
                    if ($index > 0 && $index < 5 && $punchTime->gt($outTime)) {
                        $punchTime = $outTime->copy()->subMinutes(rand(5, 30));
                    }

                    AttendanceLog::create([
                        'device_id' => $device->id,
                        'pin' => $user->pin,
                        'punched_at' => $punchTime,
                        'status' => $statuses[$index],
                        'verify_type' => $verifyType,
                    ]);
                }

                $totalLogs += 6;
            }
        }

        $this->info("Successfully generated $totalLogs attendance logs!");
    }
}

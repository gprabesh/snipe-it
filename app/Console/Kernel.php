<?php

namespace App\Console;

use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;
use App\Console\Commands\ImportLocations;
use Illuminate\Console\Scheduling\Schedule;
use App\Console\Commands\RestoreDeletedUsers;
use App\Console\Commands\ReEncodeCustomFieldNames;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->call(function () {
        //     Artisan::call('entra:sync', ['--apply' => 'true']);
        // })->name('Entra-Sync')->everyFiveMinutes()->withoutOverlapping();

        $schedule->call(function () {
            Artisan::call('intune:sync');
        })->name('Intune Sync')->everyFifteenMinutes()->withoutOverlapping();
    }

    /**
     * This method is required by Laravel to handle any console routes
     * that are defined in routes/console.php.
     */
    protected function commands()
    {
        // require base_path('routes/console.php');
        $this->load(__DIR__ . '/Commands');
    }

    public function syncCreatedUsers()
    {
        $synctype = 'created';
        $intervalmins = 1;

        $ldap_filter_settings = Setting::getSettings()->ldap_filter;
        $additional_filter = "";
        if ($intervalmins > 0) {
            $filter_time = now()->subMinutes($intervalmins)->format('YmdHis.0\Z');
            if ($synctype == 'created') {
                $additional_filter .= "(whenCreated>=$filter_time)";
            } else {
                $additional_filter .= "(whenChanged>=$filter_time)(whenCreated<=$filter_time)";
            }
        }
        $final_filter = $ldap_filter_settings . $additional_filter;
        Artisan::call('snipeit:ldap-sync-new', ['--filter' => $final_filter, '--json_summary' => true, '--synctype' => $synctype]);
    }
    public function syncUpdatedUsers()
    {
        $synctype = 'updated';
        $intervalmins = 1;

        $ldap_filter_settings = Setting::getSettings()->ldap_filter;
        $additional_filter = "";
        if ($intervalmins > 0) {
            $filter_time = now()->subMinutes($intervalmins)->format('YmdHis.0\Z');
            if ($synctype == 'created') {
                $additional_filter .= "(whenCreated>=$filter_time)";
            } else {
                $additional_filter .= "(whenChanged>=$filter_time)(whenCreated<=$filter_time)";
            }
        }
        $final_filter = $ldap_filter_settings . $additional_filter;
        Artisan::call('snipeit:ldap-sync-new', ['--filter' => $final_filter, '--json_summary' => true, '--synctype' => $synctype]);
    }
}

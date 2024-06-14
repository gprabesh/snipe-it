<?php

namespace App\Console\Commands;

use Artisan;
use App\Models\Setting;
use Illuminate\Console\Command;

class LdapSyncDynamic extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snipeit:ldap-sync-dynamic {--intervalmins=} {--synctype=}';

    protected ?string $synctype = null;
    protected int $intervalmins = 0;
    protected string $additional_filters = '';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $ldap_filter_settings = Setting::getSettings()->ldap_filter;
        $additional_filter = "";
        if ($this->option('intervalmins')) {
            $this->intervalmins = $this->option('intervalmins');
        }
        if ($this->option('synctype')) {
            $this->synctype = $this->option('synctype');
        }
        if ($this->intervalmins > 0) {
            $filter_time = now()->subMinutes($this->intervalmins)->format('YmdHis.0\Z');
            if ($this->synctype == 'created') {
                $additional_filter .= "(whenCreated>=$filter_time)";
            }
            if ($this->synctype == 'updated') {
                $additional_filter .= "(whenChanged>=$filter_time)(whenCreated<=$filter_time)";
            }
        }
        $final_filter = $ldap_filter_settings . $additional_filter;
        Artisan::call('snipeit:ldap-sync', ['--filter' => $final_filter, '--json_summary' => true]);
    }
}

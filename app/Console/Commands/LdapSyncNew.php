<?php

namespace App\Console\Commands;

use stdClass;
use App\Models\Ldap;
use App\Models\Group;
use GuzzleHttp\Client;
use App\Models\Setting;
use App\Models\Location;
use App\Classes\JsonUser;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LdapSyncNew extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'snipeit:ldap-sync-new {--location=} {--location_id=*} {--base_dn=} {--filter=} {--summary} {--json_summary} {--synctype}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command line LDAP sync';

    protected $scimHttpClient, $apiHttpClient;
    protected $existing_users;
    protected $mapped_users;
    protected $synctype = 'updated';

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
        try {
            Log::warning("<============================= Start ========================================>");

            if ($this->option('synctype')) {
                $this->synctype = $this->option('synctype');
            }
            $this->scimHttpClient = new Client([
                'headers' => [
                    'Authorization' => 'Bearer ' . config('scim.access_token'),
                    'accept' => 'application/json',
                ],
                'base_uri' => config('scim.base_url'),
            ]);
            $this->apiHttpClient = new Client([
                'headers' => [
                    'Authorization' => 'Bearer ' . config('scim.access_token'),
                    'accept' => 'application/json',
                ],
                'base_uri' => config('scim.api_base_url'),
            ]);
            ini_set('max_execution_time', env('LDAP_TIME_LIM', 600)); //600 seconds = 10 minutes
            ini_set('memory_limit', env('LDAP_MEM_LIM', '500M'));
            $ldap_result_username = Setting::getSettings()->ldap_username_field;
            $ldap_result_last_name = Setting::getSettings()->ldap_lname_field;
            $ldap_result_first_name = Setting::getSettings()->ldap_fname_field;
            $ldap_result_active_flag = Setting::getSettings()->ldap_active_flag;
            $ldap_result_emp_num = Setting::getSettings()->ldap_emp_num;
            $ldap_result_email = Setting::getSettings()->ldap_email;
            $ldap_result_phone = Setting::getSettings()->ldap_phone_field;
            $ldap_result_jobtitle = Setting::getSettings()->ldap_jobtitle;
            $ldap_result_country = Setting::getSettings()->ldap_country;
            $ldap_result_location = Setting::getSettings()->ldap_location;
            $ldap_result_dept = Setting::getSettings()->ldap_dept;
            $ldap_result_manager = Setting::getSettings()->ldap_manager;
            $ldap_default_group = Setting::getSettings()->ldap_default_group;
            $search_base = Setting::getSettings()->ldap_base_dn;

            try {
                $ldapconn = Ldap::connectToLdap();
                Ldap::bindAdminToLdap($ldapconn);
                $this->existing_users = json_decode($this->scimHttpClient->get('Users', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
            } catch (\Exception $e) {
                if ($this->option('json_summary')) {
                    $json_summary = ['error' => true, 'error_message' => $e->getMessage(), 'summary' => []];
                    $this->info(json_encode($json_summary));
                }
                Log::info($e);

                return [];
            }
            $this->mapped_users = new Collection();
            if (isset($this->existing_users) && count($this->existing_users->Resources) > 0) {
                foreach ($this->existing_users->Resources as $key => $value) {
                    $obj = new stdClass;
                    $obj->id = $value->id;
                    $obj->username = $value->{'urn:ietf:params:scim:schemas:core:2.0:User'}->userName;
                    $obj->email = $value->{'urn:ietf:params:scim:schemas:core:2.0:User'}->emails[0]->value;
                    $this->mapped_users->push($obj);
                }
            }
            $summary = [];

            try {

                /**
                 * if a location ID has been specified, use that OU
                 */
                if ($this->option('location_id')) {

                    foreach ($this->option('location_id') as $location_id) {
                        $location_ou = Location::where('id', '=', $location_id)->value('ldap_ou');
                        $search_base = $location_ou;
                        Log::debug('Importing users from specified location OU: \"' . $search_base . '\".');
                    }
                }

                /**
                 *  if a manual base DN has been specified, use that. Allow the Base DN to override
                 *  even if there's a location-based DN - if you picked it, you must have picked it for a reason.
                 */
                if ($this->option('base_dn') != '') {
                    $search_base = $this->option('base_dn');
                    Log::debug('Importing users from specified base DN: \"' . $search_base . '\".');
                }

                /**
                 * If a filter has been specified, use that
                 */
                if ($this->option('filter') != '') {
                    $results = Ldap::findLdapUsers($search_base, -1, $this->option('filter'));
                } else {
                    $results = Ldap::findLdapUsers($search_base);
                }
            } catch (\Exception $e) {
                if ($this->option('json_summary')) {
                    $json_summary = ['error' => true, 'error_message' => $e->getMessage(), 'summary' => []];
                    Log::warning(json_encode($json_summary));
                    $this->info(json_encode($json_summary));
                }
                Log::info($e);

                return [];
            }

            $location = null; // TODO - this would be better called "$default_location", which is more explicit about its purpose

            if (!isset($location)) {
                Log::debug('That location is invalid or a location was not provided, so no location will be assigned by default.');
            }

            /* Process locations with explicitly defined OUs, if doing a full import. */
            if ($this->option('base_dn') == '' && $this->option('filter') == '') {
                // Retrieve locations with a mapped OU, and sort them from the shallowest to deepest OU (see #3993)
                $ldap_ou_locations = Location::where('ldap_ou', '!=', '')->get()->toArray();
                $ldap_ou_lengths = [];

                foreach ($ldap_ou_locations as $ou_loc) {
                    $ldap_ou_lengths[] = strlen($ou_loc['ldap_ou']);
                }

                array_multisort($ldap_ou_lengths, SORT_ASC, $ldap_ou_locations);

                if (count($ldap_ou_locations) > 0) {
                    Log::debug('Some locations have special OUs set. Locations will be automatically set for users in those OUs.');
                }

                // Inject location information fields
                for ($i = 0; $i < $results['count']; $i++) {
                    $results[$i]['ldap_location_override'] = false;
                    $results[$i]['location_id'] = 0;
                }

                // Grab subsets based on location-specific DNs, and overwrite location for these users.
                foreach ($ldap_ou_locations as $ldap_loc) {
                    try {
                        $location_users = Ldap::findLdapUsers($ldap_loc['ldap_ou']);
                    } catch (\Exception $e) { // TODO: this is stolen from line 77 or so above
                        if ($this->option('json_summary')) {
                            $json_summary = ['error' => true, 'error_message' => trans('admin/users/message.error.ldap_could_not_search') . ' Location: ' . $ldap_loc['name'] . ' (ID: ' . $ldap_loc['id'] . ') cannot connect to "' . $ldap_loc['ldap_ou'] . '" - ' . $e->getMessage(), 'summary' => []];
                            $this->info(json_encode($json_summary));
                        }
                        Log::info($e);

                        return [];
                    }
                    $usernames = [];
                    for ($i = 0; $i < $location_users['count']; $i++) {
                        if (array_key_exists($ldap_result_username, $location_users[$i])) {
                            $location_users[$i]['ldap_location_override'] = true;
                            $location_users[$i]['location_id'] = $ldap_loc['id'];
                            $usernames[] = $location_users[$i][$ldap_result_username][0];
                        }
                    }

                    // Delete located users from the general group.
                    foreach ($results as $key => $generic_entry) {
                        if ((is_array($generic_entry)) && (array_key_exists($ldap_result_username, $generic_entry))) {
                            if (in_array($generic_entry[$ldap_result_username][0], $usernames)) {
                                unset($results[$key]);
                            }
                        }
                    }

                    $global_count = $results['count'];
                    $results = array_merge($location_users, $results);
                    $results['count'] = $global_count;
                }
            }


            if ($ldap_default_group != null) {

                $default = Group::find($ldap_default_group);
                if (!$default) {
                    $ldap_default_group = null; // un-set the default group if that group doesn't exist
                }
            }


            for ($i = 0; $i < $results['count']; $i++) {
                $item = [];
                $item['username'] = $results[$i][$ldap_result_username][0] ?? '';
                $item['employee_number'] = $results[$i][$ldap_result_emp_num][0] ?? '';
                $item['lastname'] = $results[$i][$ldap_result_last_name][0] ?? '';
                $item['firstname'] = $results[$i][$ldap_result_first_name][0] ?? '';
                $item['email'] = $results[$i][$ldap_result_email][0] ?? '';
                $item['ldap_location_override'] = $results[$i]['ldap_location_override'] ?? '';
                $item['location_id'] = $results[$i]['location_id'] ?? '';
                $item['telephone'] = $results[$i][$ldap_result_phone][0] ?? '';
                $item['jobtitle'] = $results[$i][$ldap_result_jobtitle][0] ?? '';
                $item['country'] = $results[$i][$ldap_result_country][0] ?? '';
                $item['department'] = $results[$i][$ldap_result_dept][0] ?? '';
                $item['manager'] = $results[$i][$ldap_result_manager][0] ?? '';
                $item['location'] = $results[$i][$ldap_result_location][0] ?? '';
                $item['website'] = $results[$i]['wwwhomepage'][0] ?? '';
                $item['address'] = $results[$i]['streetaddress'][0] ?? '';
                $item['city'] = $results[$i]['l'][0] ?? '';
                $item['state'] = $results[$i]['st'][0] ?? '';
                $item['zip'] = $results[$i]['postalcode'][0] ?? '';

                $json_user = new JsonUser;
                $json_user->first_name = $item['firstname'];
                $json_user->last_name = $item['lastname'];
                $json_user->username = $item['username'];
                $json_user->password = ucfirst($json_user->username) . "@123#";
                $json_user->password_confirmation = $json_user->password;
                $json_user->email = $item['email'];
                $json_user->phone = $item['telephone'];
                $json_user->jobtitle = $item['jobtitle'];
                $json_user->employee_num = $item['employee_number'];
                $json_user->department_id = $item['department'];
                $json_user->country = $item['country'];
                $json_user->website = $item['website'];
                $json_user->address = $item['address'];
                $json_user->city = $item['city'];
                $json_user->state = $item['state'];
                $json_user->zip = $item['zip'];


                if (array_key_exists('useraccountcontrol', $results[$i])) {
                    $enabled_accounts = [
                        '512',    //     0x200 NORMAL_ACCOUNT
                        '544',    //     0x220 NORMAL_ACCOUNT, PASSWD_NOTREQD
                        '66048',  //   0x10200 NORMAL_ACCOUNT, DONT_EXPIRE_PASSWORD
                        '66080',  //   0x10220 NORMAL_ACCOUNT, PASSWD_NOTREQD, DONT_EXPIRE_PASSWORD
                        '262656', //   0x40200 NORMAL_ACCOUNT, SMARTCARD_REQUIRED
                        '262688', //   0x40220 NORMAL_ACCOUNT, PASSWD_NOTREQD, SMARTCARD_REQUIRED
                        '328192', //   0x50200 NORMAL_ACCOUNT, SMARTCARD_REQUIRED, DONT_EXPIRE_PASSWORD
                        '328224', //   0x50220 NORMAL_ACCOUNT, PASSWD_NOT_REQD, SMARTCARD_REQUIRED, DONT_EXPIRE_PASSWORD
                        '4194816', //  0x400200 NORMAL_ACCOUNT, DONT_REQ_PREAUTH
                        '4260352', // 0x410200 NORMAL_ACCOUNT, DONT_EXPIRE_PASSWORD, DONT_REQ_PREAUTH
                        '1049088', // 0x100200 NORMAL_ACCOUNT, NOT_DELEGATED
                        '1114624', // 0x110200 NORMAL_ACCOUNT, DONT_EXPIRE_PASSWORD, NOT_DELEGATED,
                    ];
                    $item['activated'] = (in_array($results[$i]['useraccountcontrol'][0], $enabled_accounts)) ? 1 : 0;
                    $json_user->activated = (in_array($results[$i]['useraccountcontrol'][0], $enabled_accounts)) ? true : false;

                    // If we're not using AD, and there isn't an activated flag set, activate all users
                }

                array_push($summary, $item);

                $user_exists = $this->mapped_users->search(function ($value, $key) use ($item) {
                    return $value->username == $item['username'] && $value->email == $item['email'];
                });
                if ($user_exists === false && $this->synctype == 'created') {
                    $response = $this->apiHttpClient->post('users', [
                        'form_params' => (array)$json_user

                    ]);
                    Log::error($response->getBody());
                } elseif ($user_exists !== false) {
                    unset($json_user->password);
                    unset($json_user->password_confirmation);
                    unset($json_user->department_id);
                    $response = $this->apiHttpClient->request("PATCH", "users/" . $this->mapped_users->get($user_exists)->id, [
                        'form_params' => (array)$json_user
                    ]);
                    Log::error($response->getBody());
                }
            }

            if ($this->option('summary')) {
                Log::warning(json_encode($summary));
                for ($x = 0; $x < count($summary); $x++) {
                    if ($summary[$x]['status'] == 'error') {
                        $this->error('ERROR: ' . $summary[$x]['firstname'] . ' ' . $summary[$x]['lastname'] . ' (username:  ' . $summary[$x]['username'] . ') was not imported: ' . $summary[$x]['note']);
                    } else {
                        $this->info('User ' . $summary[$x]['firstname'] . ' ' . $summary[$x]['lastname'] . ' (username:  ' . $summary[$x]['username'] . ') was ' . strtoupper($summary[$x]['createorupdate']) . '.');
                    }
                }
            } elseif ($this->option('json_summary')) {
                $json_summary = ['error' => false, 'error_message' => '', 'summary' => $summary]; // hardcoding the error to false and the error_message to blank seems a bit weird
                Log::warning(json_encode($json_summary));
                Log::warning("<============================= End ========================================>");
                $this->info(json_encode($json_summary));
            } else {
                return $summary;
            }
        } catch (\Throwable $th) {
            Log::error($th);
        }
    }
}

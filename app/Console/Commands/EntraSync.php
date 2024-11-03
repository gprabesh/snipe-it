<?php

namespace App\Console\Commands;

use stdClass;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Users\UsersRequestBuilderGetRequestConfiguration;

class EntraSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'entra:sync {--apply=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync users from Microsoft Entra. a.k.a Azure active directory to snipeit';

    protected $apiHttpClient;
    protected $existing_users;
    protected $mapped_users;
    protected $entra_users;
    // protected $synctype = 'created';
    protected $filter = "userType eq 'Member'";
    // protected int $intervalmins = 1;
    protected ClientCredentialContext $tokenRequestContext;
    protected GraphServiceClient $graphServiceClient;
    protected array $scopes;
    // protected $filter_time;
    protected $apply = 'false';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(ClientCredentialContext $tokenRequestContext)
    {
        parent::__construct();
        $this->tokenRequestContext = $tokenRequestContext;
        $this->scopes = ['https://graph.microsoft.com/.default'];
        $this->graphServiceClient = new GraphServiceClient($this->tokenRequestContext, $this->scopes);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::channel('entra')->info("<============================= Start Entra Sync ========================================>");

        // if ($this->option('intervalmins')) {
        //     $this->intervalmins = $this->option('intervalmins');
        // }
        // if ($this->option('synctype')) {
        //     $this->synctype = $this->option('synctype');
        // }
        if ($this->option('apply')) {
            $this->apply =  $this->option('apply');
        }
        // $this->filter_time = now()->subMinutes($this->intervalmins)->toIso8601String();
        // if ($this->synctype == 'created') {
        //     $this->filter .= " and createdDateTime ge $this->filter_time";
        // } else {
        //     $this->filter .= " and onPremisesLastSyncDateTime ge $this->filter_time and createdDateTime le $this->filter_time";
        // }
        $this->apiHttpClient = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . config('scim.access_token'),
                'accept' => 'application/json',
            ],
            'base_uri' => config('scim.api_base_url'),
        ]);
        ini_set('memory_limit', env('LDAP_MEM_LIM', '1G'));
        $userRequestConfig = new UsersRequestBuilderGetRequestConfiguration();
        $userRequestConfig->queryParameters = UsersRequestBuilderGetRequestConfiguration::createQueryParameters();
        $userRequestConfig->queryParameters->select = ["displayName", "givenName", "jobTitle", "mail", "mobilePhone", "officeLocation", "preferredLanguage", "surname", "userPrincipalName", "userType", "accountEnabled", "city", "onPremisesSamAccountName", "employeeId", "mobilePhone", "streetAddress", "state", "postalCode", "createdDateTime", "onPremisesLastSyncDateTime" . "country"];
        $userRequestConfig->queryParameters->filter = $this->filter;
        $userRequestConfig->queryParameters->top = 999;

        $totalEntraUsers = 0;
        $totalSnipeItUsers = 0;
        $activeEntraUsers = 0;
        $activeSnipeItUsers = 0;
        $inactiveEntraUsers = 0;
        $inactiveSnipeItUsers = 0;
        $createdUsers = 0;
        $failedToCreateUsers = 0;
        $updatedUsers = 0;
        $failedToUpdateUsers = 0;
        $invalidDataUsers = 0;
        $needsUpdateCount = 0;


        $this->existing_users = json_decode($this->apiHttpClient->get('users', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
        Log::channel('entra')->info('Fetched existing users from snipeit');
        $this->mapped_users = new Collection();
        if (isset($this->existing_users) && count($this->existing_users->rows) > 0) {
            foreach ($this->existing_users->rows as $key => $value) {
                if ($value->activated == true) {
                    $activeSnipeItUsers += 1;
                } else {
                    $inactiveSnipeItUsers += 1;
                }
                $this->mapped_users->push($value);
            }
        }
        $totalSnipeItUsers = count($this->mapped_users);

        $this->graphServiceClient->users()->get($userRequestConfig)->then(function ($data) {
            $this->entra_users = $data->getValue();
        });
        Log::channel('entra')->info('Fetched existing users from entra');
        foreach ($this->entra_users as $key => $value) {
            $totalEntraUsers += 1;
            $userResource = $value->getBackingStore();
            $transformedUser = $this->transformDevice($userResource);
            if ($transformedUser->activated == true) {
                $activeEntraUsers += 1;
            } else {
                $inactiveEntraUsers += 1;
            }
            $user_exists = $this->mapped_users->search(function ($value, $key) use ($transformedUser) {
                return strtolower($value->username) == strtolower($transformedUser->username) && strtolower($value->email) == strtolower($transformedUser->email);
            });
            $isValidUser = $this->validateUser($transformedUser);
            if (!$isValidUser && $transformedUser->activated == true) {
                $invalidDataUsers += 1;
                continue;
            }
            if ($user_exists === false && $transformedUser->activated == true && $this->apply == 'true') {
                $response = $this->apiHttpClient->post('users', [
                    'form_params' => (array)$transformedUser
                ]);
                if ($response_body = json_decode($response->getBody())) {
                    Log::channel('entra')->info($response->getBody());
                    if ($response_body->status == "success") {
                        $createdUsers += 1;
                    } else {
                        $failedToCreateUsers += 1;
                        Log::channel('entra')->error('Failed to create user with data: ' . json_encode($transformedUser));
                    }
                } else {
                    $failedToCreateUsers += 1;
                    Log::channel('entra')->error('Something went wrong while creating user with data: ' . json_encode($transformedUser));
                }
            } elseif ($user_exists !== false) {
                unset($transformedUser->password);
                unset($transformedUser->password_confirmation);
                $validateUpdateNeed = $this->validateForUpdate($this->mapped_users->get($user_exists), $transformedUser);
                if (!$validateUpdateNeed) {
                    continue;
                } else {
                    $needsUpdateCount += 1;
                }
                if ($this->apply !== 'false') {
                    $response = $this->apiHttpClient->request("PATCH", "users/" . $this->mapped_users->get($user_exists)->id, [
                        'form_params' => (array)$transformedUser
                    ]);
                    if ($response_body = json_decode($response->getBody())) {
                        Log::channel('entra')->info($response->getBody());
                        if ($response_body->status == "success") {
                            $updatedUsers += 1;
                        } else {
                            $failedToUpdateUsers += 1;
                            Log::channel('entra')->error('Failed to update user with data: ' . json_encode($transformedUser));
                        }
                    } else {
                        Log::channel('entra')->error('Something went wrong while updating user with data: ' . json_encode($transformedUser));
                    }
                }
            }
        }
        Log::channel('entra')->info("Users created/updated in entra Count: " . $totalEntraUsers);
        $this->info('Users created/updated in entra Count: ' . $totalEntraUsers);
        Log::channel('entra')->info("Total SnipeIt Users Count: " . $totalSnipeItUsers);
        $this->info('Total SnipeIt Users Count: ' . $totalSnipeItUsers);
        Log::channel('entra')->info("Active entra users Count: " . $activeEntraUsers);
        $this->info('Active entra users Count: ' . $activeEntraUsers);
        Log::channel('entra')->info("Active SnipeIt Users Count: " . $activeSnipeItUsers);
        $this->info('Active SnipeIt Users Count: ' . $activeSnipeItUsers);
        Log::channel('entra')->info("Inactive entra users Count: " . $inactiveEntraUsers);
        $this->info('Inactive entra users Count: ' . $inactiveEntraUsers);
        Log::channel('entra')->info("Inactive SnipeIt users Count: " . $inactiveSnipeItUsers);
        $this->info('Inactive SnipeIt users Count: ' . $inactiveSnipeItUsers);
        Log::channel('entra')->info("New Created users Count: " . $createdUsers);
        $this->info('New Created users Count: ' . $createdUsers);
        Log::channel('entra')->info("Failed to create users Count: " . $failedToCreateUsers);
        $this->info('Failed to create users Count: ' . $failedToCreateUsers);
        Log::channel('entra')->info("Users needing update Count: " . $needsUpdateCount);
        $this->info('Users needing update Count: ' . $needsUpdateCount);
        Log::channel('entra')->info("Updated users Count: " . $updatedUsers);
        $this->info('Updated users Count: ' . $updatedUsers);
        Log::channel('entra')->info("Failed to update users Count: " . $failedToUpdateUsers);
        $this->info('Failed to update users Count: ' . $failedToUpdateUsers);
        Log::channel('entra')->info("Invalid data users Count: " . $invalidDataUsers);
        $this->info('Invalid data users Count: ' . $invalidDataUsers);

        Log::channel('entra')->info("<============================= End Entra Sync ========================================>");
    }
    public function transformDevice($userResource)
    {
        $obj = new stdClass;
        $obj->first_name = $userResource->get("givenName");
        $obj->last_name = $userResource->get("surname");
        $obj->username = $userResource->get("onPremisesSamAccountName");
        $obj->password = ucfirst($obj->username) . strtolower($obj->last_name) . "@123#";
        $obj->password_confirmation = $obj->password;
        $obj->email = $userResource->get("userPrincipalName");
        $obj->phone = $userResource->get("mobilePhone");
        $obj->jobtitle = $userResource->get("jobTitle");
        $obj->employee_num = $userResource->get("employeeId");
        $obj->country = $userResource->get("country");
        $obj->website = $userResource->get("mySite");
        $obj->address = $userResource->get("streetAddress");
        $obj->city = $userResource->get("city");
        $obj->state = $userResource->get("state");
        $obj->zip = $userResource->get("postalCode");
        $obj->activated = $userResource->get("accountEnabled") ?? false;

        $obj->userType = $userResource->get("userType");
        $obj->onPremisesSamAccountName = $userResource->get("onPremisesSamAccountName");
        $obj->createdDateTime = $userResource->get("createdDateTime");
        $obj->onPremisesLastSyncDateTime = $userResource->get("onPremisesLastSyncDateTime");
        return $obj;
    }
    public function validateUser($transformedUser)
    {
        $valid = true;
        if (empty($transformedUser->first_name) || empty($transformedUser->last_name) || empty($transformedUser->username)) {
            $valid = false;
        }
        return $valid;
    }
    public function validateForUpdate($snipeItUser, $entraUser)
    {
        $needsUpdate = false;
        if ($snipeItUser->first_name != $entraUser->first_name) {
            $needsUpdate = true;
        }
        if ($snipeItUser->last_name != $entraUser->last_name) {
            $needsUpdate = true;
        }
        if ($snipeItUser->phone != $entraUser->phone) {
            $needsUpdate = true;
        }
        if ($snipeItUser->jobtitle != $entraUser->jobtitle) {
            $needsUpdate = true;
        }
        if ($snipeItUser->employee_num != $entraUser->employee_num) {
            $needsUpdate = true;
        }
        if ($snipeItUser->country != $entraUser->country) {
            $needsUpdate = true;
        }
        if ($snipeItUser->website != $entraUser->website) {
            $needsUpdate = true;
        }
        if ($snipeItUser->address != $entraUser->address) {
            $needsUpdate = true;
        }
        if ($snipeItUser->city != $entraUser->city) {
            $needsUpdate = true;
        }
        if ($snipeItUser->state != $entraUser->state) {
            $needsUpdate = true;
        }
        if ($snipeItUser->zip != $entraUser->zip) {
            $needsUpdate = true;
        }
        if ($snipeItUser->activated != $entraUser->activated) {
            $needsUpdate = true;
        }
        return $needsUpdate;
    }
}

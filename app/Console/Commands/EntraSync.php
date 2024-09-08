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
    protected $signature = 'entra:sync {--intervalmins=} {--synctype=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync users from Microsoft Entra. a.k.a Azure active directory to snipeit';

    protected $scimHttpClient, $apiHttpClient;
    protected $existing_users;
    protected $mapped_users;
    protected $entra_users;
    protected $synctype = 'updated';
    protected $filter = "userType eq 'Member'";
    protected int $intervalmins = 1;
    protected ClientCredentialContext $tokenRequestContext;
    protected GraphServiceClient $graphServiceClient;
    protected array $scopes;
    protected $filter_time;

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

        if ($this->option('intervalmins')) {
            $this->intervalmins = $this->option('intervalmins');
        }
        if ($this->option('synctype')) {
            $this->synctype = $this->option('synctype');
        }
        $this->filter_time = now()->subMinutes($this->intervalmins)->toIso8601String();
        if ($this->synctype == 'created') {
            $this->filter .= " and createdDateTime ge $this->filter_time";
        } else {
            $this->filter .= " and onPremisesLastSyncDateTime ge $this->filter_time and createdDateTime le $this->filter_time";
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
        ini_set('memory_limit', env('LDAP_MEM_LIM', '1G'));
        $userRequestConfig = new UsersRequestBuilderGetRequestConfiguration();
        $userRequestConfig->queryParameters = UsersRequestBuilderGetRequestConfiguration::createQueryParameters();
        $userRequestConfig->queryParameters->select = ["displayName", "givenName", "jobTitle", "mail", "mobilePhone", "officeLocation", "preferredLanguage", "surname", "userPrincipalName", "userType", "accountEnabled", "city", "onPremisesSamAccountName", "employeeId", "mobilePhone", "streetAddress", "state", "postalCode", "createdDateTime", "onPremisesLastSyncDateTime" . "country"];
        $userRequestConfig->queryParameters->filter = $this->filter;

        $this->existing_users = json_decode($this->scimHttpClient->get('Users', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
        Log::channel('entra')->info('Fetched existing users from snipeit');
        $this->mapped_users = new Collection();
        if (isset($this->existing_users) && count($this->existing_users->Resources) > 0) {
            foreach ($this->existing_users->Resources as $key => $value) {
                $obj = new stdClass;
                $obj->id = $value->id;
                // $obj->username = $value->userName;
                // $obj->email = $value->emails[0]->value;
                $obj->username = $value->{'urn:ietf:params:scim:schemas:core:2.0:User'}->userName;
                $obj->email = $value->{'urn:ietf:params:scim:schemas:core:2.0:User'}->emails[0]->value;

                $this->mapped_users->push($obj);
            }
        }
        dd($this->mapped_users);
        $this->graphServiceClient->users()->get($userRequestConfig)->then(function ($data) {
            $this->entra_users = $data->getValue();
        });
        foreach ($this->entra_users as $key => $value) {
            $userResource = $value->getBackingStore();
            $transformedUser = $this->transformDevice($userResource);
            // $userExists = $this->assertUserExists($transformedUser);
        }
    }
    public function transformDevice($userResource)
    {
        $obj = new stdClass;
        $obj->displayName = $userResource->get("displayName");
        $obj->givenName = $userResource->get("givenName");
        $obj->jobTitle = $userResource->get("jobTitle");
        $obj->mail = $userResource->get("mail");
        $obj->mobilePhone = $userResource->get("mobilePhone");
        $obj->officeLocation = $userResource->get("officeLocation");
        $obj->preferredLanguage = $userResource->get("preferredLanguage");
        $obj->surname = $userResource->get("surname");
        $obj->userPrincipalName = $userResource->get("userPrincipalName");
        $obj->userType = $userResource->get("userType");
        $obj->accountEnabled = $userResource->get("accountEnabled");
        $obj->city = $userResource->get("city");
        $obj->country = $userResource->get("country");
        $obj->onPremisesSamAccountName = $userResource->get("onPremisesSamAccountName");
        $obj->employeeId = $userResource->get("employeeId");
        $obj->mobilePhone = $userResource->get("mobilePhone");
        $obj->streetAddress = $userResource->get("streetAddress");
        $obj->state = $userResource->get("state");
        $obj->postalCode = $userResource->get("postalCode");
        $obj->createdDateTime = $userResource->get("createdDateTime");
        $obj->onPremisesLastSyncDateTime = $userResource->get("onPremisesLastSyncDateTime");
        return $obj;
    }
    public function assertUserExists($transformedUser)
    {
        return $this->mapped_users->search(function ($value, $key) use ($transformedUser) {
            return strtolower($value->email) == strtolower($transformedUser->userPrincipalName);
        });
    }
}

<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

use Microsoft\Graph\GraphServiceClient;
use Microsoft\Graph\Generated\Models\ManagedDevice;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Graph\Generated\Devices\DevicesRequestBuilderGetRequestConfiguration;
use stdClass;

class IntuneSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intune:sync {--synctype}';

    protected ClientCredentialContext $tokenRequestContext;
    protected GraphServiceClient $graphServiceClient;
    protected array $scopes;
    protected array $devices;
    protected $apiHttpClient, $existing_devices, $mapped_devices,$existingModels,$mappedModels;
    protected $synctype = 'updated';

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
        Log::warning("<============================= Start Intune Sync ========================================>");

        if ($this->option('synctype')) {
            $this->synctype = $this->option('synctype');
        }
        $this->apiHttpClient = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . config('scim.access_token'),
                'accept' => 'application/json',
            ],
            'base_uri' => config('scim.api_base_url'),
        ]);
        ini_set("memory_limit", "1G");
        try {
            $this->existing_devices = json_decode($this->apiHttpClient->get('hardware', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
        } catch (\Throwable $th) {
            Log::error($th);
            return;
        }
        dd($this->existing_devices);
        dd('there');
        $this->mapped_devices = new Collection();
        if (isset($existing_devices) && count($this->existing_devices) > 0) {
            foreach ($this->existing_devices as $key => $value) {
                $obj = new stdClass();
                $obj->id = $value->id;
                $obj->serial = $value->serial;
                $this->mapped_devices->push($obj);
            }
        }
        $deviceRequestConfiguration = new DevicesRequestBuilderGetRequestConfiguration();
        $queryParameters = DevicesRequestBuilderGetRequestConfiguration::createQueryParameters();
        $queryParameters->select = ["odataType", "id", "deletedDateTime", "accountEnabled", "approximateLastSignInDateTime", "complianceExpirationDateTime", "deviceCategory", "deviceId", "deviceMetadata", "deviceOwnership", "deviceVersion", "displayName", "enrollmentProfileName", "enrollmentType", "isCompliant", "isManaged", "isRooted", "managementType", "manufacturer", "mdmAppId", "model", "onPremisesLastSyncDateTime", "onPremisesSyncEnabled", "operatingSystem", "operatingSystemVersion", "physicalIds", "profileType", "registrationDateTime", "systemLabels", "trustType", "hardwareInformation"];
        $deviceRequestConfiguration->queryParameters = $queryParameters;
        $this->graphServiceClient->deviceManagement()->managedDevices()->get()->then(function ($data) {
            // dd($data);
            $this->devices =  $this->getDeviceModelsFromPromise($data);
            // dd($this->devices);
        });
        // dd($this->devices);
        foreach ($this->devices as $key => $value) {
            $deviceResouce = $value->getBackingStore();
            dd($deviceResouce);
            Log::error(json_encode($deviceResouce->get("serialNumber")));
            $deviceSerialIndex = $deviceResouce->get("serialNumber");
            if (is_array($deviceSerialIndex)) {
                # code...
            }
            // Log::error($deviceSerialIndex);
            if ($deviceSerialIndex === null) {
                continue; //skip the device which does not have serial
            }
            $serialNo = $deviceResouce->get("serialNumber")[1];
            if ($deviceResouce->get("displayName") == "DESKTOP-UVVTRQN") {
                dd($value);
            }
            // Log::error($serialNo);
            if ($serialNo == "PW09MNY8") {
                dd($value);
            }
            $device_exists = $this->mapped_devices->search(function ($value, $key) use ($deviceResouce) {
                return $value->serial == $deviceResouce->get("serialNumber")[1];
            });

            if ($device_exists === false && $this->synctype == 'created') {
            } elseif ($device_exists !== false) {
            }
        }
    }

    public function getDeviceModelsFromPromise($data)
    {
        return $data->getValue();
    }
    public function transformDevice($deviceResource)
    {
        $obj = new stdClass;
        $obj->name = $deviceResource->get("deviceName")[1];
        $obj->serial = $deviceResource->get("serialNumber")[1];
        $obj->notes = $deviceResource->get("notes")[1] ?? "";
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
        $obj->name = $deviceResource->get();
    }
}

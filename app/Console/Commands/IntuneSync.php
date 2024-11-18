<?php

namespace App\Console\Commands;

use stdClass;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use App\Exceptions\CustomException;
use Illuminate\Support\Facades\Log;
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;

class IntuneSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'intune:sync';

    protected ClientCredentialContext $tokenRequestContext;
    protected GraphServiceClient $graphServiceClient;
    protected array $scopes;
    protected array $devices;
    protected $apiHttpClient, $existing_devices, $mapped_devices, $transformed_mapped_intune_devices, $existingModels, $existingCategories, $existingStatusLabels, $defaultStatusId, $defaultAssetStatus, $defaultModelCategoryType;

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
        $this->defaultAssetStatus = "deployable";
        $this->defaultModelCategoryType = "Asset";
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            Log::channel('intune')->warning("<============================= Start Intune Sync ========================================>");

            $this->apiHttpClient = new Client([
                'headers' => [
                    'Authorization' => 'Bearer ' . config('scim.access_token'),
                    'accept' => 'application/json',
                ],
                'base_uri' => config('scim.api_base_url'),
            ]);
            ini_set("memory_limit", "1G");
            $this->graphServiceClient->deviceManagement()->managedDevices()->get()->then(function ($data) {
                $this->devices = $data->getValue();
            });
            try {
                $this->existing_devices = json_decode($this->apiHttpClient->get('hardware', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
                $this->existingModels = json_decode($this->apiHttpClient->get('models', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
                $this->existingCategories = json_decode($this->apiHttpClient->get('categories', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
                $this->existingStatusLabels = json_decode($this->apiHttpClient->get('statuslabels', ['query' => ['startIndex' => 0, 'count' => 2000]])->getBody());
            } catch (\Throwable $th) {
                Log::channel('intune')->error($th);
                return;
            }
            $this->mapped_devices = new Collection();
            if (isset($this->existing_devices) && count($this->existing_devices->rows) > 0) {
                foreach ($this->existing_devices->rows as $key => $value) {
                    $obj = new stdClass();
                    $obj->id = $value->id;
                    $obj->name = $value->name;
                    $obj->serial = $value->serial;
                    $obj->assigned_to = $value->assigned_to;
                    $this->mapped_devices->push($obj);
                }
            }
            $existingStatusLabelsCollection = collect($this->existingStatusLabels->rows);
            $statusLabelExists = $existingStatusLabelsCollection->search(function ($value, $key) {
                return $value->type == $this->defaultAssetStatus;
            });
            $this->defaultStatusId = $existingStatusLabelsCollection->get($statusLabelExists)->id;
            foreach ($this->devices as $key => $value) {
                $deviceResouce = $value->getBackingStore();
                $transformedDevice = $this->transformDevice($deviceResouce);
                if (strpos($transformedDevice->serial, "VMware-") !== false || $transformedDevice->serial == "0" || $transformedDevice->serial == "5B9MNH2") {
                    continue;
                }
                if (!isset($this->transformed_mapped_intune_devices[$transformedDevice->serial]) || Carbon::parse($transformedDevice->enrolledDate) > Carbon::parse($this->transformed_mapped_intune_devices[$transformedDevice->serial]->enrolledDate)) {
                    $this->transformed_mapped_intune_devices[$transformedDevice->serial] = $transformedDevice;
                }
            }
            foreach ($this->transformed_mapped_intune_devices as $key => $value) {
                $transformedDevice = $value;
                $deviceExists = $this->assertDeviceExists($transformedDevice);
                try {
                    if ($deviceExists === false) {
                        $modelCollection = collect($this->existingModels->rows);
                        $modelExists = $this->assertModelExists($transformedDevice, $modelCollection);
                        if ($modelExists === false) {
                            $modelId = $this->createModel($transformedDevice->modelName);
                        } else {
                            $modelId = $modelCollection->get($modelExists)->id;
                        }
                        $transformedDevice->model_id = $modelId;
                        unset($transformedDevice->modelName);
                        $response = $this->apiHttpClient->post('hardware', [
                            'form_params' => (array)$transformedDevice
                        ]);
                        if ($response->getStatusCode() !== 200) {
                            throw new CustomException("Failed to create device $transformedDevice->serial . Something went wrong");
                        }
                        Log::channel('intune')->error($response->getBody());
                        Log::channel('intune')->error("Asset created");
                        $responseObj = json_decode($response->getBody());
                        if ($responseObj->status !== "success") {
                            throw new CustomException("Failed to create device $transformedDevice->serial . Something went wrong");
                        }
                        if (!empty($transformedDevice->userPrincipalName)) {
                            try {
                                $userId = $this->assertUserExists($transformedDevice->userPrincipalName);
                            } catch (CustomException $ce) {
                                Log::channel('intune')->error($ce->getMessage());
                                $userId = null;
                            } catch (\Throwable $th) {
                                Log::channel('intune')->error($th);
                                $userId = null;
                            }
                            if ($userId > 0) {
                                $this->deployDevice($responseObj->payload->id, $userId);
                            }
                        }
                    } elseif ($deviceExists >= 0) {
                        $mapped_device = $this->mapped_devices->get($deviceExists);
                        $deviceRequiresUpdate = $this->assertDeviceInfoUpdated($mapped_device, $transformedDevice);
                        if ($deviceRequiresUpdate) {
                            try {
                                $this->updateDeviceName($mapped_device->id, $transformedDevice);
                            } catch (CustomException $ce) {
                                Log::channel('intune')->error($ce->getMessage());
                            } catch (\Throwable $th) {
                                Log::channel('intune')->error($th);
                            }
                        }
                        if ($mapped_device->assigned_to == null && empty($transformedDevice->userPrincipalName)) {
                            continue;
                        }
                        if ($mapped_device->assigned_to != null && empty($transformedDevice->userPrincipalName)) {
                            $this->checkinDevice($mapped_device->id);
                            continue;
                        }
                        if ($mapped_device->assigned_to == null && !empty($transformedDevice->userPrincipalName)) {
                            try {
                                $userId = $this->assertUserExists($transformedDevice->userPrincipalName);
                            } catch (CustomException $ce) {
                                Log::channel('intune')->error($ce->getMessage());
                                $userId = null;
                            } catch (\Throwable $th) {
                                Log::channel('intune')->error($th);
                                $userId = null;
                            }
                            if ($userId > 0) {
                                $this->deployDevice($mapped_device->id, $userId);
                            }
                            continue;
                        }
                        if ($mapped_device->assigned_to != null && !empty($transformedDevice->userPrincipalName) && (strtolower($mapped_device->assigned_to->email) !== strtolower($transformedDevice->userPrincipalName))) {
                            try {
                                $userId = $this->assertUserExists($transformedDevice->userPrincipalName);
                            } catch (CustomException $ce) {
                                Log::channel('intune')->error($ce->getMessage());
                                $userId = null;
                            } catch (\Throwable $th) {
                                Log::channel('intune')->error($th);
                                $userId = null;
                            }
                            if ($userId > 0) {
                                $this->checkinDevice($mapped_device->id);
                                $this->deployDevice($mapped_device->id, $userId);
                            }
                        }
                    }
                } catch (CustomException $ce) {
                    Log::channel('intune')->error($ce->getMessage());
                } catch (\Throwable $th) {
                    Log::channel('intune')->error($th);
                }
            }
            Log::channel('intune')->warning("<============================= End Intune Sync ========================================>");
        } catch (\Throwable $th) {
            Log::channel('intune')->error($th);
        }
    }
    public function transformDevice($deviceResource)
    {
        $obj = new stdClass;
        $obj->name = $deviceResource->get("deviceName");
        $obj->serial = $deviceResource->get("serialNumber");
        $obj->notes = $deviceResource->get("notes") ?? "";
        $obj->asset_tag = $obj->name;
        $obj->status_id = $this->defaultStatusId;
        $obj->modelName = $deviceResource->get("model") ?? "";
        $obj->enrolledDate = Carbon::parse($deviceResource->get("enrolledDateTime"));
        $obj->lastSyncDateTime = Carbon::parse($deviceResource->get("lastSyncDateTime"));
        $obj->userPrincipalName = $deviceResource->get("userPrincipalName");
        return $obj;
    }
    public function assertDeviceExists($transformedDevice)
    {
        return $this->mapped_devices->search(function ($value, $key) use ($transformedDevice) {
            return $value->serial == $transformedDevice->serial;
        });
    }
    public function assertModelExists($transformedDevice, $modelCollection)
    {
        return $modelCollection->search(function ($value, $key) use ($transformedDevice) {
            return $value->name == $transformedDevice->modelName;
        });
    }

    public function assertUserExists($email)
    {
        $getUserResponse = $this->apiHttpClient->get("users", ['query' => ['startIndex' => 0, 'count' => 2000, 'email' => $email]]);
        if ($getUserResponse->getStatusCode() != 200) {
            throw new CustomException("Failed to get user with email $email. Something went wrong");
        }
        $decodedGetUserResponse = json_decode($getUserResponse->getBody());
        if ($decodedGetUserResponse->total == 0) {
            throw new CustomException("Failed to get user $email. User does not exist");
        }
        $userId = collect($decodedGetUserResponse->rows)->first()->id;
        return $userId;
    }

    public function createModel($model)
    {
        $existingCategoriesCollection = collect($this->existingCategories->rows);
        $categoryExists = $existingCategoriesCollection->search(function ($value, $key) {
            return $value->category_type == $this->defaultModelCategoryType;
        });
        if ($categoryExists === false) {
            throw new CustomException("Model Category not found");
        }
        $response = $this->apiHttpClient->post('models', [
            'form_params' => ['name' => $model, 'category_id' => $existingCategoriesCollection->get($categoryExists)->id]
        ]);
        if ($response_body = json_decode($response->getBody())) {
            Log::channel('intune')->error($response->getBody());
            if ($response_body->status == "success") {
                array_push($this->existingModels->rows, $response_body->payload);
                return $response_body->payload->id;
            }
        }
        throw new CustomException("Failed to create model for $model");
    }

    public function checkinDevice($deviceId)
    {
        $response = $this->apiHttpClient->post("hardware/$deviceId/checkin");
        $decoded = json_decode($response->getBody());
        if (isset($decoded) && $decoded->status != "success") {
            Log::channel('intune')->error($response->getBody());
            throw new CustomException("Failed to checkin device with id $deviceId");
        }
        Log::channel('intune')->error($response->getBody());
    }
    public function deployDevice($deviceId, $userId)
    {
        $response = $this->apiHttpClient->post("hardware/$deviceId/checkout", ['form_params' => [
            'status_id' => $this->defaultStatusId,
            'checkout_to_type' => 'user',
            'assigned_user' => $userId
        ]]);
        if ($response->getStatusCode() != 200) {
            throw new CustomException("Failed to checkout device with id $deviceId . Something went wrong");
        }
        $decoded = json_decode($response->getBody());

        if (isset($decoded) && $decoded->status != "success") {
            Log::channel('intune')->error($response->getBody());
            throw new CustomException("Failed to checkout device with id $deviceId");
        }
        Log::channel('intune')->error($response->getBody());
    }

    public function assertDeviceInfoUpdated($snipeItDevice, $intuneDevice)
    {
        $needsUpdate = false;
        if ($snipeItDevice->name != $intuneDevice->name) {
            $needsUpdate = true;
        }
        return $needsUpdate;
    }

    public function updateDeviceName($deviceId, $intuneDevice)
    {
        $response = $this->apiHttpClient->patch("hardware/$deviceId", ['form_params' => [
            'name' => $intuneDevice->name,
            'asset_tag' => $intuneDevice->asset_tag
        ]]);
        if ($response->getStatusCode() != 200) {
            throw new CustomException("Failed to update device with id $deviceId . Something went wrong");
        }
        $decoded = json_decode($response->getBody());

        if (isset($decoded) && $decoded->status != "success") {
            Log::channel('intune')->error($response->getBody());
            throw new CustomException("Failed to update device with id $deviceId");
        }
        Log::channel('intune')->error($response->getBody());
    }
}

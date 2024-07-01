<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
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
        $result = $this->graphServiceClient->devices()->get()->wait();
        dd($result);
        return 0;
    }
}

<?php

namespace App\Console\Commands;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Libraries\Analyzers\DxI800AsClient;
use App\Libraries\Analyzers\SysmexAsClient;
use Exception;
use Illuminate\Console\Command;

class StartDxI800AsClientCommand extends Command
{
    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        parent::__construct();
        $this->resultService = $resultService;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dxi800:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the server for DxI analyzer connection';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $defaultServer = DxI800AsClient::getInstance($this->resultService);

        while ($counter < 10) {
            $connection = $defaultServer->start();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Server for DxI800 analyzer started');
            $defaultServer->process();
        }
    }
}

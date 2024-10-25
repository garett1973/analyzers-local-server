<?php

namespace App\Console\Commands;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Libraries\Analyzers\SysmexAsClient;
use Exception;
use Illuminate\Console\Command;

class StartSysmexAsClientCommand extends Command
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
    protected $signature = 'sysmex:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the server for Sysmex analyzer connection';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $defaultServer = SysmexAsClient::getInstance($this->resultService);

        while ($counter < 10) {
            $connection = $defaultServer->start();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Server for Sysmex analyzer started');
            $defaultServer->process();
        }
    }
}

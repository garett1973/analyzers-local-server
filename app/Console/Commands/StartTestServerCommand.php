<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Test\TestServer;
use Exception;
use Illuminate\Console\Command;

class StartTestServerCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the test server for analyzer connection';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $testServer = TestServer::getInstance();

        while ($counter < 10) {
            $connection = $testServer->start();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Test server started');
            $testServer->process();
        }
    }
}

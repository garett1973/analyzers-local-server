<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Default\DefaultServer;
use Exception;
use Illuminate\Console\Command;

class StartDefaultServerCommand extends Command
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
    protected $signature = 'default:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the default server for analyzer connection';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $defaultServer = DefaultServer::getInstance();

        while ($counter < 10) {
            $connection = $defaultServer->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Default server started');
            $defaultServer->process();
        }
    }
}

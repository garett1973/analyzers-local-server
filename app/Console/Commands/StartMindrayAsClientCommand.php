<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Default\DefaultHL7Client;
use App\Libraries\Analyzers\MindrayAsClient;
use Exception;
use Illuminate\Console\Command;

class StartMindrayAsClientCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mindray:start';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the server for Mindray connection as client';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $connection = false;
        $mindrayClient = MindrayAsClient::getInstance();

        while (!$connection) {
            $connection = $mindrayClient->start();
            sleep(10);
        }

        $this->info('Server for Mindray client started');
        $mindrayClient->process();
    }
}

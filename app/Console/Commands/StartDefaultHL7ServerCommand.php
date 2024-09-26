<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Default\DefaultHL7Server;
use Exception;
use Illuminate\Console\Command;

class StartDefaultHL7ServerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hl:start';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the default HL7 server for analyzer connection';

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
        $defaultHL7Server = DefaultHL7Server::getInstance();

        while (!$connection) {
            $connection = $defaultHL7Server->start();
            sleep(10);
        }

        $this->info('Default HL7 server started');
        $defaultHL7Server->process();
    }
}

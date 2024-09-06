<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Maglumi;
use Exception;
use Illuminate\Console\Command;

class ConnectToMaglumiAnalyzerCommand extends Command
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
    protected $signature = 'maglumi:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connects to Maglumi analyzer and processes the data';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $maglumi = Maglumi::getInstance();

        while ($counter < 10) {
            $connection = $maglumi->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Connected to Maglumi');
            $maglumi->process();
        }

    }
}

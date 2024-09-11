<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Premier_Hb9210;
use Exception;
use Illuminate\Console\Command;

class ConnectToPremierAnalyzerCommand extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'premier:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connects to PremierHb9210 analyzer and processes the data';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $premier = Premier_Hb9210::getInstance();

        while ($counter < 10) {
            $connection = $premier->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Connected to Premier_Hb9210 Analyzer');
            $premier->process();
        }
    }
}

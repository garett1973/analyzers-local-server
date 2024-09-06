<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Premier_Hb9210;
use App\Libraries\Analyzers\UniCell_Dxl_800;
use Exception;
use Illuminate\Console\Command;

class ConnectToUniCellAnalyzerCommand extends Command
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
    protected $signature = 'unicell:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connects to UniCell_Dxl800 analyzer and processes the data';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $unicell = UniCell_Dxl_800::getInstance();

        while ($counter < 10) {
            $connection = $unicell->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Connected to UniCell_Dxl800 Analyzer');
            $unicell->process();
        }
    }
}

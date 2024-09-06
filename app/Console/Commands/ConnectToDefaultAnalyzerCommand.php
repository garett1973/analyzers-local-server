<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\DefaultAnalyzer;
use App\Libraries\Analyzers\Maglumi;
use Exception;
use Illuminate\Console\Command;

class ConnectToDefaultAnalyzerCommand extends Command
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
    protected $signature = 'default:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connects to the default analyzer';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $defaultAnalyzer = DefaultAnalyzer::getInstance();

        while ($counter < 10) {
            $connection = $defaultAnalyzer->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Connected to Default Analyzer');
            $defaultAnalyzer->process();
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\TestAnalyzer;
use Illuminate\Console\Command;

class TestConnectionCommand extends Command
{

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connects to the test analyzer';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $testAnalyzer = TestAnalyzer::getInstance();

        while ($counter < 10) {
            $connection = $testAnalyzer->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Connected to test Analyzer');
            $testAnalyzer->process();
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Test\TestClient;
use Illuminate\Console\Command;

class TestClientConnectionCommand extends Command
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
        $testAnalyzer = TestClient::getInstance();

        while ($counter < 100) {
            $connection = $testAnalyzer->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(5);
        }

        if ($connection) {
            $this->info('Connected to test Analyzer');
            $testAnalyzer->process();
        }
    }
}

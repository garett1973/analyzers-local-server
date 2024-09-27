<?php

namespace App\Console\Commands;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Libraries\Analyzers\PremierClient;
use Exception;
use Illuminate\Console\Command;

class ConnectPremierClientCommand extends Command
{
    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        parent::__construct();
        $this->resultService = $resultService;
    }

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
    protected $description = 'Connects to the Premier Analyzer as client';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $defaultAnalyzer = PremierClient::getInstance($this->resultService);

        while ($counter < 10) {
            $connection = $defaultAnalyzer->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Connected to Premier Analyzer');
            $defaultAnalyzer->process();
        }
    }
}

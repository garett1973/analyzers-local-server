<?php

namespace App\Console\Commands;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Libraries\Analyzers\Default\DefaultClient;
use App\Libraries\Analyzers\Maglumi;
use Exception;
use Illuminate\Console\Command;

class ConnectAsDefaultClientCommand extends Command
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
        $defaultAnalyzer = DefaultClient::getInstance($this->resultService);

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

<?php

namespace App\Console\Commands;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Libraries\Analyzers\BioMaximaAsServer;
use Exception;
use Illuminate\Console\Command;

class StartBioMaximaAsServerCommand extends Command
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
    protected $signature = 'biomaxima:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connects to the BioMaxima Analyzer as client';

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $counter = 0;
        $connection = false;
        $biomaximaAnalyzer = BioMaximaAsServer::getInstance($this->resultService);

        while ($counter < 10) {
            $connection = $biomaximaAnalyzer->connect();
            if ($connection) {
                break;
            }
            $counter++;
            sleep(10);
        }

        if ($connection) {
            $this->info('Connected to BioMaxima Analyzer');
            $biomaximaAnalyzer->process();
        }
    }
}

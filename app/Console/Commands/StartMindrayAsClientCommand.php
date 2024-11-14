<?php

namespace App\Console\Commands;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Libraries\Analyzers\MindrayAsClient;
use Exception;
use Illuminate\Console\Command;

class StartMindrayAsClientCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mindray:start';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Starts the server for Mindray connection as client';

    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        parent::__construct();
        $this->resultService = $resultService;
    }

    /**
     * Execute the console command.
     * @throws Exception
     */
    public function handle(): void
    {
        $connection = false;
        $mindrayClient = MindrayAsClient::getInstance($this->resultService);

        while (!$connection) {
            $connection = $mindrayClient->start();
            sleep(10);
        }

        $this->info('Server for Mindray client started');
        $mindrayClient->process();
    }
}

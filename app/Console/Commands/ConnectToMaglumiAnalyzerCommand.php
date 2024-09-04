<?php

namespace App\Console\Commands;

use App\Libraries\Analyzers\Maglumi;
use parallel\Runtime;
use Exception;
use Illuminate\Console\Command;

class OpenSocketConnectionsCommand extends Command
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
    protected $signature = 'sockets:connect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Connects to all the available analyzers';

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
//            $runtime = new Runtime();
//            $future = $runtime->run(function() {
//                require_once __DIR__ . '/../../../vendor/autoload.php'; // Adjust the path as needed
//                $maglumi = Maglumi::getInstance();
//                $maglumi->connect();
//                echo "Child process: Connected to Maglumi\n";
//                $maglumi->process();
//                echo "Child process: Finished processing\n";
//            });
//
//            // Parent process
//            sleep(10);
//            $maglumi->setReceiving(false);
//            echo "Parent process: Set receiving to false\n";
//
//            // Wait for the child process to complete
//            $future->value();
//            echo "Parent process: Child process completed\n";
        }

    }
}

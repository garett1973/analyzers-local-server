<?php

namespace App\Console\Commands;

use App\Models\Analyzer;
use App\Services\SocketManager;
use Illuminate\Console\Command;

class SocketServer extends Command
{
    protected $signature = 'socket:serve';
    protected $description = 'Start a socket server to listen for messages on multiple ports';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(SocketManager $socketManager)
    {
        $analyzers = Analyzer::where('lab_id', config('laboratories.lab_id'))
            ->where('is_active', 1)
            ->get();

        $port = 12000; // List of ports to listen on

        // Create and bind sockets to each port
        foreach ($analyzers as $analyzer) {
            if (isset($analyzer->local_ip)) {
                $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
                $connection = socket_connect($socket, $analyzer->local_ip, $port);
                $socketManager->addConnection($analyzer->name, $connection);
                $this->info("Server started on port {$port}");
                $port++;
            } else {
                $this->error("No local IP address found for {$analyzer->name}");
            }
        }
    }
}

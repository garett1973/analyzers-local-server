<?php

namespace App\Console\Commands;

use App\Helpers\NetworkHelper;
use Illuminate\Console\Command;

class ManageConnections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'connections:manage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage connections to multiple servers for two-way communication';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $servers = [
            ['ip' => '192.168.1.10', 'port' => 8080],
            ['ip' => '192.168.1.11', 'port' => 8081],
            // Add more servers as needed
        ];

        foreach ($servers as $server) {
            if (NetworkHelper::checkConnection($server['ip'], $server['port'])) {
                $this->connectToServer($server['ip'], $server['port']);
            } else {
                $this->error("Connection to {$server['ip']}:{$server['port']} is not enabled");
            }
        }
    }

    private function connectToServer(mixed $ip, mixed $port): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connection = @socket_connect($socket, $ip, $port);

        if ($connection) {
            // Send a message to the server
            socket_write($socket, "Hello Server", strlen("Hello Server"));

            // Listen for a response
            $response = socket_read($socket, 2048); // Adjust the buffer size as needed

            // Process the response
            $this->info("Received response from {$ip}:{$port} - " . $response);

            // Close the socket connection
            socket_close($socket);
        } else {
            $this->error("Failed to connect to {$ip}:{$port}");
        }
    }
}

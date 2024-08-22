<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SocketServer extends Command
{
    protected $signature = 'socket:serve';
    protected $description = 'Start a socket server to listen for messages on multiple ports';

    public function handle()
    {
        $ports = [8080, 8081]; // List of ports to listen on
        $sockets = [];

        // Create and bind sockets to each port
        foreach ($ports as $port) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            socket_bind($socket, '0.0.0.0', $port);
            socket_listen($socket);
            $sockets[] = $socket;
            $this->info("Server started on port {$port}");
        }

        while (true) {
            $read = $sockets;
            $write = null;
            $except = null;

            // Use socket_select to monitor multiple sockets
            if (socket_select($read, $write, $except, null) > 0) {
                foreach ($read as $socket) {
                    $client = socket_accept($socket);
                    $input = socket_read($client, 2048);

                    // Process the received message
                    $this->info("Received message: " . $input);

                    // Send a response back to the client
                    socket_write($client, "ACK", strlen("ACK"));

                    socket_close($client);
                }
            }
        }

        // Close all sockets (this part will never be reached in this infinite loop)
        foreach ($sockets as $socket) {
            socket_close($socket);
        }
    }
}

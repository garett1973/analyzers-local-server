<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TestArduinoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:arduino';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
//        $host = 'garett1973.hopto.org'; // Replace with your Moxa module's IP address
//        $port = 9999; // Replace with your Moxa module's port

        $host = '85.206.48.46';
//        $host = '192.168.1.111';
        $port = 9999;

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
            return;
        }

        $result = socket_connect($socket, $host, $port);
        if ($result === false) {
            echo "Socket connection failed: " . socket_strerror(socket_last_error($socket)) . "\n";
            return;
        }
        $signals = [
            "\x05", // ENQ
            "\x04", // EOT
            "\x02", // STX
            "\x06", // ACK
            "Some other signal coming from unknown analyzer\n",
        ];

        foreach ($signals as $signal) {
            socket_write($socket, $signal, strlen($signal));
            echo "Sent: " . bin2hex($signal) . "\n";

            $response = socket_read($socket, 2048);
            echo "Received: " . $response . "\n";
        }

        socket_close($socket);
    }
}

<?php

namespace App\Libraries\Analyzers\Test;

use App\Enums\HexCodes;
use Illuminate\Support\Facades\Log;

class TestServer
{
    const ACK = HexCodes::ACK->value;
    const ENQ = HexCodes::ENQ->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;

    private static ?TestServer $instance = null;
    private $server_socket;
    private $client_socket;

    public function __construct()
    {
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->server_socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(): TestServer
    {
        if (self::$instance == null) {
            self::$instance = new TestServer();
        }

        return self::$instance;
    }

    public function start(): bool
    {
        // rezus addresses
//        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
//        $port = 9999;

//        $ip = '62.80.253.55'; // public address

//        $ip = '127.0.0.1';
//        $port = 12000;

        $ip = '192.168.0.111';
        $port = 9999;


        // Create socket
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->server_socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
            return false;
        }

        // Bind socket
        if (socket_bind($this->server_socket, $ip, $port) === false) {
            echo "Socket bind failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }

        // Listen on socket
        if (socket_listen($this->server_socket, 5) === false) {
            echo "Socket listen failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }

        echo "Socket server started on $ip:$port\n";

        // Accept client connection
        $this->client_socket = socket_accept($this->server_socket);
        if ($this->client_socket === false) {
            echo "Socket accept failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }

        echo "Client connected\n";
//        socket_write($this->client_socket, 'Hello from server, you stupid bastard!', strlen('Hello from server, you stupid bastard!'));
        socket_getpeername($this->client_socket, $client_ip);
        echo "Client IP: $client_ip\n";

        return true;
    }

    public function process(): void
    {
        while (true) {
            // Check if the client socket is still connected
            if ($this->isClientDisconnected()) {
                echo "Client socket error\n";
                $this->handleClientDisconnection();
                break;
            }

            $inc = socket_read($this->client_socket, 2024);
            if ($inc) {
                // Check if the incoming data is a control signal
                if ($inc === self::ENQ) {
                    Log::channel('test_server_log')->info("Received ENQ at " . now());
                    echo "Received ENQ\n";
                } else {
                    if ($inc === self::EOT) {
                        Log::channel('test_server_log')->info("Received EOT at " . now());
                        echo "Received EOT\n";
                    } else {
                        Log::channel('test_server_log')->info("Received data at " . now() . ": $inc");
                        echo "Received data: $inc\n";
                        // Convert the received data to a hexadecimal string for headers/results
                        $hexInc = bin2hex($inc);
                        Log::channel('test_server_log')->info("Received (hex): $hexInc");
                        echo "Received (hex): $hexInc\n";
                    }
                }

                if ($inc !== self::EOT) {
                    socket_write($this->client_socket, self::ACK, strlen(self::ACK));
                    Log::channel('test_server_log')->info("Sent ACK at " . now());
                    echo "Sent ACK: " . bin2hex(self::ACK) . "\n";
                }
            }
        }
    }


    private function isClientDisconnected(): bool
    {
        echo "Checking client socket\n";
        return socket_get_option($this->client_socket, SOL_SOCKET, SO_ERROR) !== 0;
    }

    private function handleClientDisconnection(): void
    {
        // Implement reconnection logic or cleanup here
        $this->reconnect();
    }

    private function reconnect(): void
    {
        Log::channel('test_server_log')->error(now() . ' -> Waiting for client to reconnect ...');
        echo "Waiting for client to reconnect...\n";

        // Close the current client socket
        socket_close($this->client_socket);

        // Wait for a new client connection
        $this->client_socket = socket_accept($this->server_socket);
        if ($this->client_socket === false) {
            echo "Socket accept failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            sleep(10); // Wait before trying again
            $this->reconnect();
        } else {
            echo "Client reconnected\n";
            socket_getpeername($this->client_socket, $client_ip);
            echo "Client IP: $client_ip\n";
            $this->process();
        }
    }
}

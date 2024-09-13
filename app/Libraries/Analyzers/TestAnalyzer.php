<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use Illuminate\Support\Facades\Log;

class TestAnalyzer
{
    const ACK = HexCodes::ACK->value;
    const ENQ = HexCodes::ENQ->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;

    private static ?TestAnalyzer $instance = null;
    private $socket;
    private $connection;

    public function __construct()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(): TestAnalyzer
    {
        if (self::$instance == null) {
            self::$instance = new TestAnalyzer();
        }

        return self::$instance;
    }

    public function connect(): bool
    {
        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
        $port = 9999;

        $ip = '127.0.0.1';
        $port = 12000;

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            echo "Socket connection failed: " . socket_strerror(socket_last_error($this->socket)) . "\n";
        } else {
            echo "Socket connected\n";
        }

        return $this->connection;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function process(): void
    {
        if ($this->connection) {
            echo "Connection established\n";
            while (true) {
                $inc = socket_read($this->socket, 2024);
                if ($inc) {
                    // Check if the incoming data is a control signal
                    if ($inc === self::ENQ) {
                        Log::channel('premier_test')->info("Received ENQ at " . now());
                        echo "Received ENQ\n";
                    } else if ($inc === self::EOT) {
                        Log::channel('premier_test')->info("Received EOT at " . now());
                        echo "Received EOT\n";
                    } else {
                        Log::channel('premier_test')->info("Received data at " . now() . ": $inc");
                        echo "Received data: $inc\n";
                        // Convert the received data to a hexadecimal string for headers/results
                        $hexInc = bin2hex($inc);
                        Log::channel('premier_test')->info("Received (hex): $hexInc");
                        echo "Received (hex): $hexInc\n";
                    }

                    if ($inc !== self::EOT) {
                        // Send ACK
                        $ack = "\x06";
                        socket_write($this->socket, $ack, strlen($ack));
                        Log::channel('premier_test')->info("Sent ACK at " . now());
                        echo "Sent ACK: " . bin2hex(self::ACK) . "\n";
                    }
                }
            }
        }
    }
}

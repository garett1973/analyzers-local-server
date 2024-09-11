<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Models\Order;

class TestAnalyzer
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;
    const TERMINATOR = self::CR . self::LF;

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
//        $ip = '192.168.0.110';
//        $port = 9999;
;
//        $ip = '193.219.86.180';
//        $port = '6669';

//        $ip = '127.0.0.1';
//        $port = 12000;

//        $ip = '0.tcp.eu.ngrok.io';
//        $port = 18525;

//        $ip = 'garett1973.hopto.org';
//        $port = 9999;

        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
        $port = 9999;

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
                        echo "Received ENQ\n";
                    } else if ($inc === self::STX) {
                        echo "Received STX\n";
                    } else if ($inc === self::ETX) {
                        echo "Received ETX\n";
                    } else if ($inc === self::EOT) {
                        echo "Received EOT\n";
                    } else {
                        // Convert the received data to a hexadecimal string for headers/results
                        $hexInc = bin2hex($inc);
                        echo "Received (hex): $hexInc\n";
                    }
                    $ack = "\x06";
                    socket_write($this->socket, $ack, strlen($ack));

                    echo "Sent ACK: " . bin2hex(self::ACK) . "\n";
                }
            }
        }
    }

}

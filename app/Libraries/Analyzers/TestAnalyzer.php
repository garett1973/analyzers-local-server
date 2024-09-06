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
//        $ip = '10.150.9.64';
//        $port = 53266;
;
//        $ip = '193.219.86.180';
//        $port = '6669';

        $ip = '127.0.0.1';
        $port = 12000;

        $ip = '0.tcp.eu.ngrok.io';
        $port = 18525;

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
                $inc = socket_read($this->socket, 1024);
                if ($inc) {
                    echo "Received: $inc\n";
                }
                if ($inc == self::ENQ) {
                    echo "ENQ received\n";
                    socket_write($this->socket, self::ACK, strlen(self::ACK));
                    echo "ACK sent\n";
                }
            }
        }
    }
}

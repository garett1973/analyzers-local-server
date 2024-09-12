<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;

class Premier_Hb9210
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;
    public const TERMINATOR = self::CR . self::LF;

    private static ?Premier_Hb9210 $instance = null;
    private $socket;
    private $connection;

    public function __construct()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(): Premier_Hb9210
    {
        if (self::$instance == null) {
            self::$instance = new Premier_Hb9210();
        }

        return self::$instance;
    }

    public function connect(): bool
    {
//        $ip = '127.0.0.1';
//        $port = 12000;

//        $ip = '192.168.1.111';
        $ip = '85.206.48.46';
        $port = 9999;


        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            echo "Socket connection failed: " . socket_strerror(socket_last_error($this->socket)) . "\n";
        } else {
            echo "Socket connected\n";
        }

        return $this->connection;
    }

    public function process(): void
    {
        if ($this->connection) {
            echo "Connection established\n";
            while (true) {
                $inc = socket_read($this->socket, 1024);
                if ($inc) {
                    switch ($inc) {
                        case self::ENQ:
                            $this->handleEnq();
                            break;
                        case self::EOT:
                            $this->handleEot();
                            break;
                        default:
                            $header = $this->getDataMessageHeader($inc);
                            switch ($header) {
                                case 'H':
                                    $this->handleHeader($inc);
                                    break;
                                case 'P':
                                    $this->handlePatient($inc);
                                    break;
                                case 'O':
                                    $this->handleOrder($inc);
                                    break;
                                case 'R':
                                    $this->handleResult($inc);
                                    break;
                                case 'L':
                                    $this->handleTerminator($inc);
                                    break;
                            }
                    }
                }
            }
        }
    }

    private function handleEnq(): void
    {
        echo "ENQ received\n";
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        echo "ACK sent\n";
    }

    private function handleEot(): void
    {
        echo "EOT received\n";
    }

    private function sendACK(): void
    {
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        echo "ACK sent\n";
    }

    private function getDataMessageHeader($inc): false|string
    {
        return explode('|', $inc)[0];
    }

    private function handleHeader(string $inc): void
    {
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function handlePatient(string $inc): void
    {
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        echo "Order data received: $inc\n";
        $this->sendACK();
    }

    private function handleResult(string $inc): void
    {
        echo "Result received: $inc\n";
        $this->sendACK();
    }

    private function handleTerminator(string $inc): void
    {
        echo "Terminator received: $inc\n";
        $this->sendACK();
    }
}

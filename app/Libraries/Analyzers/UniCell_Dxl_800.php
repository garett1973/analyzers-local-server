<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;

class Premier
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

    private static ?Premier $instance = null;
    private $socket;
    private $connection;

    public function __construct()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(): Premier
    {
        if (self::$instance == null) {
            self::$instance = new Premier();
        }

        return self::$instance;
    }

    public function connect(): bool
    {
        $ip = '127.0.0.1';
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

    private function getDataMessageHeader($inc): false|string
    {
        echo "Data message received: $inc\n";
        echo "Data message hex2bin: " . hex2bin($inc) . "\n";
        // remove CR from message end
        $inc = substr($inc, 0, -1);
        $inc = hex2bin($inc);
        return explode('|', $inc)[0];
    }

    private function handleHeader(string $inc): void
    {
        // remove CR from message end
        $inc = substr($inc, 0, -1);
        $inc = hex2bin($inc);
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function sendACK(): void
    {
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        echo "ACK sent\n";
    }

    private function handlePatient(string $inc): void
    {
        // remove CR from message end
        $inc = substr($inc, 0, -1);
        $inc = hex2bin($inc);
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        // remove CR from message end
        $inc = substr($inc, 0, -1);
        $inc = hex2bin($inc);
        echo "Order data received: $inc\n";
        $this->sendACK();
    }

    private function handleResult(string $inc): void
    {
        // remove CR from message end
        $inc = substr($inc, 0, -1);
        $inc = hex2bin($inc);
        echo "Result received: $inc\n";
        $this->sendACK();
    }

    private function handleTerminator(string $inc): void
    {
        // remove CR from message end
        $inc = substr($inc, 0, -1);
        $inc = hex2bin($inc);
        echo "Terminator received: $inc\n";
        $this->sendACK();
    }
}

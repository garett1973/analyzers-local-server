<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Http\Services\Interfaces\ResultServiceInterface;
use Illuminate\Support\Facades\Log;

class BioMaximaAsServer
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private static ?BioMaximaAsServer $instance = null;
    private $socket;
    private $connection;
    private string $barcode = '';
    private array $results = [];
    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        $this->resultService = $resultService;
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(ResultServiceInterface $resultService): BioMaximaAsServer
    {
        if (self::$instance === null) {
            self::$instance = new BioMaximaAsServer($resultService);
        }

        return self::$instance;
    }

    public function process(): void
    {
        while (true) {
            $this->setSocketOptions();
            while ($this->connection) {
                $inc = @socket_read($this->socket, 1024); // Suppress error output

                if ($inc === false || $inc === '') {
                    $this->handleReceivingError();
                    $this->resetFunction();
                    continue;
                }

                if ($inc) {
                    $this->handleIncomingMessage($inc);
                }
            }
            $this->closeConnection();
            $this->reconnect();
        }
    }

    private function setSocketOptions(): void
    {
        echo "Socket options set\n";
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 15, 'usec' => 0]);
    }

    private function handleReceivingError(): void
    {
        $this->connection = false;
        echo "Receiving error occurred\n";
    }

    private function handleIncomingMessage(string $inc): void
    {
        switch ($inc) {
            case self::ENQ:
                $this->handleEnq();
                break;
            case self::EOT:
                $this->handleEot();
                break;
            default:
                $this->processDataMessage($inc);
                break;
        }
    }

    private function handleEnq(): void
    {
        echo "ENQ received\n";
        Log::channel('biomaxima_log')->info(' -> ENQ received');
        $this->sendACK();
    }

    private function sendACK(): void
    {
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        Log::channel('biomaxima_log')->info(' -> ACK sent');
        echo "ACK sent\n";
    }

    private function handleEot(): void
    {
        echo "EOT received\n";
        Log::channel('biomaxima_log')->info(' -> EOT received');

//        $saved_results = false;
//        if (!empty($this->results)) {
//            $saved_results = $this->saveResults();
//        }
//
//        if ($saved_results) {
//            LOG::channel('biomaxima_log')->info(' -> Results saved successfully');
//            $this->sendACK();
//        } else {
//            $this->sendNAK();
//        }
//
//        $this->barcode = '';
//        $this->results = [];
    }

    private function processDataMessage(string $inc): void
    {
        echo "Received string: $inc\n";
        echo "Hex: " . bin2hex($inc) . "\n";
//        $inc = bin2hex($inc);

        $result_segments = $this->splitResultSegments($inc);
        foreach (array_slice($result_segments, 4) as $result_segment) {
            $this->handleResult($result_segment);
        }
    }

    private function splitResultSegments(string $inc): array
    {
        return explode(self::CR . self::LF, $inc);
    }


        private function handleResult(string $segment): void
    {
        Log::channel('biomaxima_log')->info(' -> Result received: ' . $segment);
    }

    private function saveResults(): bool
    {
        $saved_results = true;

        foreach ($this->results as $result_data) {
            $saved_results = $this->resultService->createResult($result_data);
            if (!$saved_results) {
                break;
            }
        }

        return $saved_results;
    }

    public function closeConnection(): void
    {
        if ($this->socket) {
            socket_close($this->socket);
            $this->socket = null;
        }
        $this->connection = false;
    }

    private function reconnect(): void
    {
        Log::channel('biomaxima_log')->error(' Reconnecting...');
        echo "Reconnecting...\n";
        if ($this->socket) {
            socket_close($this->socket);
        }
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP); // Recreate socket

        while (!$this->connect()) {
            sleep(10);
        }
    }

    public function connect(): bool
    {
//        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
//        $port = 9999;

//        $ip = '127.0.0.1';
        $ip = '192.168.0.111';
        $port = 12000;

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            $errorMessage = socket_strerror(socket_last_error($this->socket));
            echo "Socket connection failed: $errorMessage\n";
            Log::channel('biomaxima_log')->error(" -> Socket connection failed. Error: " . $errorMessage);
            return false;
        }
        echo "Connection established\n";
        Log::channel('biomaxima_log')->debug(' -> Connection to analyzer established');
        return true;
    }

    private function resetFunction(): void
    {
        $this->results = [];
    }
}

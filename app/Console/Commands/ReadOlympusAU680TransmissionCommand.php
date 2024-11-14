<?php

namespace App\Console\Commands;

use App\Enums\HexCodes;
use App\Http\Services\Interfaces\ResultServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReadOlympusAU680TransmissionCommand extends Command
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    protected $signature = 'start:olympus';
    protected $description = 'Handles communication with Olympus AU680';
    private $serverSocket;
    private $clientSocket;
    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        parent::__construct();
        $this->resultService = $resultService;
    }

    public function handle()
    {
        $this->info('Starting Olympus AU680 Transmission Reader');

        if ($this->initializeServer()) {
            $this->waitForClientConnection();
        }
    }

    private function initializeServer(): bool
    {
        $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->serverSocket === false) {
            Log::channel("au680_test_log")->error(" -> Socket creation failed: " . socket_strerror(socket_last_error()));
            return false;
        }

        $ip = '192.168.0.111';
        $port = 12000;

        if (!@socket_bind($this->serverSocket, $ip, $port)) {
            Log::channel("au680_test_log")->error(" -> Socket bind failed: " . socket_strerror(socket_last_error($this->serverSocket)));
            return false;
        }

        if (!@socket_listen($this->serverSocket, 5)) {
            Log::channel("au680_test_log")->error(" -> Socket listen failed: " . socket_strerror(socket_last_error($this->serverSocket)));
            return false;
        }

        Log::channel("au680_test_log")->info(" -> Server socket initialized at IP $ip, Port $port and listening for connections...");
        return true;
    }

    private function waitForClientConnection(): void
    {
        while (true) {
            Log::channel("au680_test_log")->info(" -> Waiting for client to connect...");

            $this->clientSocket = socket_accept($this->serverSocket);
            if ($this->clientSocket === false) {
                Log::channel("au680_test_log")->error(" -> Socket accept failed: " . socket_strerror(socket_last_error($this->serverSocket)));
                sleep(5); // Retry delay
                continue;
            }

            socket_getpeername($this->clientSocket, $clientIp);
            Log::channel("au680_test_log")->info(" -> Client connected from IP: $clientIp");
            $this->processClientMessages();
        }
    }

    private function processClientMessages(): void
    {
        Log::channel("au680_test_log")->info(" -> Starting to process client messages...");

        while (true) {
            if ($this->isClientDisconnected()) {
                Log::warning("Client disconnected.");
                $this->handleClientDisconnection();
                break;
            }

            $inc = @socket_read($this->clientSocket, 1024);
            echo "Message received: $inc\n";
            echo "Message in binary: " . hex2bin($inc) . "\n";
            if ($inc === false) {
                Log::channel("au680_test_log")->error(" -> Socket read failed: " . socket_strerror(socket_last_error($this->clientSocket)));
                break;
            }

            Log::channel("au680_test_log")->info(" -> Message received: " . $inc);
            Log::channel("au680_test_log")->info(" -> Message in binary: " . hex2bin($inc));
            $this->processIncomingMessage($inc);
        }
    }

    private function isClientDisconnected(): bool
    {
        return socket_get_option($this->clientSocket, SOL_SOCKET, SO_ERROR) !== 0;
    }

    private function handleClientDisconnection(): void
    {
        socket_close($this->clientSocket);
        Log::channel("au680_test_log")->info(" -> Waiting for client reconnection...");
        $this->waitForClientConnection();
    }

    private function processIncomingMessage(string $inc): void
    {
        $this->sendACK();
    }

    private function sendACK(): void
    {
        if (socket_write($this->clientSocket, self::ACK, strlen(self::ACK)) === false) {
            Log::error('Failed to send ACK: ' . socket_strerror(socket_last_error($this->clientSocket)));
        } else {
            Log::channel("au680_test_log")->info(' -> ACK sent');
        }
    }
}

<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Models\Analyte;
use Exception;
use Illuminate\Support\Facades\Log;

class PremierAsServer
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private static ?PremierAsServer $instance = null;
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

    public static function getInstance(ResultServiceInterface $resultService): PremierAsServer
    {
        if (self::$instance === null) {
            self::$instance = new PremierAsServer($resultService);
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
//                    $inc = bin2hex($inc); // Convert to hex - not sure if this is necessary for all the analyzers
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
        Log::channel('premier_log')->info(' -> ENQ received');
        $this->sendACK();
    }

    private function sendACK(): void
    {
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        Log::channel('premier_log')->info(' -> ACK sent');
        echo "ACK sent\n";
    }

    private function handleEot(): void
    {
        echo "EOT received\n";
        Log::channel('premier_log')->info(' -> EOT received');

        $saved_results = false;
        if (!empty($this->results)) {
            $saved_results = $this->saveResults();
        }

        if ($saved_results) {
            LOG::channel('premier_log')->info(' -> Results saved successfully');
            $this->sendACK();
        } else {
            $this->sendNAK();
        }

        $this->barcode = '';
        $this->results = [];
    }

    private function processDataMessage(string $inc): void
    {
        echo "Received string: $inc\n";
        echo "Hex: " . bin2hex($inc) . "\n";
        $inc = bin2hex($inc);
        $header = $this->getDataMessageFirstSegment($inc);

        if (!$header) {
            $this->sendNAK();
            return;
        }

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

    private function getDataMessageFirstSegment($inc): false|string
    {
        $inc = $this->processMessage($inc);
        if (!$inc) {
            return false;
        }

        $first_segment = explode('|', $inc)[0];
        return preg_replace('/[^a-zA-Z]/', '', $first_segment);
    }

    private function processMessage(string $inc): ?string
    {
        $checksum = $this->extractChecksum($inc);
        $preparedInc = $this->prepareMessageForChecksum($inc);

        if (!$this->isChecksumValid($preparedInc, $checksum)) {
            return false;
        }

        return $this->cleanMessage($inc);
    }

    private function extractChecksum(string $inc): string
    {
        // Remove LF, CR from message end and extract checksum
        $inc = substr($inc, 0, -4);
        $checksum = substr($inc, -4);
        return strtoupper(hex2bin($checksum));
    }

    private function prepareMessageForChecksum(string $inc): string
    {
        // Remove STX from message start and LF, CR, checksum from message end
        return substr(substr($inc, 2), 0, -8);
    }

    private function isChecksumValid(string $inc, string $checksum): bool
    {
        // Remove leading zero from checksum if present
        if ($checksum[0] == '0') {
            $checksum = substr($checksum, 1);
        }

        $calculatedChecksum = $this->calculateChecksum($inc);
        if ($calculatedChecksum !== $checksum) {
            return false;
        }

        return true;
    }

    private function calculateChecksum(string $inc): string
    {
        $hexArray = str_split($inc, 2);
        $checksum = array_reduce($hexArray, function ($carry, $hex) {
            return $carry + hexdec($hex);
        }, 0);

        $checksum &= 0xFF;
        return strtoupper(dechex($checksum));
    }

    private function cleanMessage(string $inc): string
    {
        // Remove STX from the start
        $inc = substr($inc, 2);

        // Remove LF, CR, checksum, ETX, CR from the end
        $inc = substr($inc, 0, -12);

        return hex2bin($inc);
    }

    private function sendNAK(): void
    {
        socket_write($this->socket, self::NAK, strlen(self::NAK));
        Log::channel('premier_log')->info(' -> NAK sent');
        echo "NAK sent\n";
    }

    private function handleHeader(string $inc): void
    {
        Log::channel('premier_log')->info(' -> Header received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('premier_log')->info(' -> Header string: ' . $inc);
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function handlePatient(string $inc): void
    {
        Log::channel('premier_log')->info(' -> Patient received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('premier_log')->info(' -> Patient string: ' . $inc);
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        Log::channel('premier_log')->info(' -> Order received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('premier_log')->info(' -> Order string: ' . $inc);
        echo "Order data received: $inc\n";

        $this->barcode = explode('|', $inc)[3];
        $this->barcode = preg_replace('/[^0-9]/', '', $this->barcode);
        echo "Barcode from order string in results: $this->barcode\n";

        $this->sendACK();
    }

    private function handleResult(string $inc): void
    {
        Log::channel('premier_log')->info(' -> Result received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('premier_log')->info(' -> Result string: ' . $inc);
        echo "Result received: $inc\n";

        [, , $analyte_name, $result, $unit] = explode('|', $inc);
        $analyte_name = ltrim($analyte_name, "^");

        $analyte = Analyte::where('name', $analyte_name)->first();
        $analyte_id = $analyte ? $analyte->analyte_id : 'N/A';

        $result_data = [
            'lab_id' => env('LAB_ID'),
            'barcode' => $this->barcode,
            'analyte_id' => $analyte_id,
            'analyte_name' => $analyte_name,
            'result' => $result,
            'unit' => $unit,
            'original_string' => $inc,
        ];

        $this->results[] = $result_data;
        $this->sendACK();
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

    private function handleTerminator(string $inc): void
    {
        Log::channel('premier_log')->info(' -> Terminator received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('premier_log')->info(' -> Terminator string: ' . $inc);
        echo "Terminator received: $inc\n";
        $this->sendACK();
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
        Log::channel('premier_log')->error(' Reconnecting...');
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

        $ip = '127.0.0.1';
        $ip = '192.168.0.111';
        $port = 12000;

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            $errorMessage = socket_strerror(socket_last_error($this->socket));
            echo "Socket connection failed: $errorMessage\n";
            Log::channel('premier_log')->error(" -> Socket connection failed. Error: " . $errorMessage);
            return false;
        }
        echo "Connection established\n";
        Log::channel('premier_log')->debug(' -> Connection to analyzer established');
        return true;
    }

    private function resetFunction(): void
    {
        $this->results = [];
    }
}

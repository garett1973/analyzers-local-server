<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Models\Analyte;
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
    private string $msg = '';
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
                $inc = @socket_read($this->socket, 1024 * 4); // Suppress error output

                if ($inc === false || $inc === '') {
                    $this->handleReceivingError();
                    $this->resetFunction();
                    continue;
                }

                echo "Received: $inc\n";
                $inc = bin2hex($inc);
                echo "Received hex: $inc\n";
                $full_received = $this->checkIfLastPartReceived($inc);
                $this->msg .= $inc;
                if ($full_received) {
                    echo "Full message received\n";
                    echo "-----------------------------------------------\n";
                    $this->handleReceivedMessage();
                    $this->msg = '';
                }
            }
            $this->closeConnection();
            $this->reconnect();
        }
    }

    private function setSocketOptions(): void
    {
        echo "Socket options set\n";
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 60, 'usec' => 0]);
    }

    private function handleReceivingError(): void
    {
        $this->connection = false;
        echo "Receiving error occurred\n";
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    private function checkIfLastPartReceived(string $inc): bool
    {
        echo "Checking if last part received\n";
        return $this->endsWith($inc, bin2hex(self::CR . self::LF . self::ETX));
    }

    private function handleReceivedMessage(): void
    {
        $segments = $this->splitResultSegments();

        foreach ($segments as $segment) {
            echo "Segment: $segment\n";
            echo "Segment binary: " . hex2bin($segment) . "\n";
            if ($segment === '') {
                continue;
            }
            $this->handleMessageSegment($segment);
        }

        $this->barcode = explode(':', hex2bin($segments[1]))[1];

        $this->getResults($segments);

        $results_saved = $this->saveResults();
        $this->resetFunction(); // Reset results array
    }


    private function splitResultSegments(): array
    {
        return explode(bin2hex(self::CR . self::LF), $this->msg);
    }

    private function handleMessageSegment(string $segment): void
    {
        Log::channel('biomaxima_test_log')->info(' -> Segment received: ' . $segment);
        LOG::channel('biomaxima_test_log')->info(' -> Segment received: ' . hex2bin($segment));
    }

    private function getResults(array $segments): void
    {
        $results_array = array_slice($segments, 5, 15);
        foreach ($results_array as $segment) {

            $result_data = [];
            $result_data['original_string'] = hex2bin($segment);

            // remove fist 2 characters in segment - segment is in hex format and first character is either space or *
            $segment = substr($segment, 2);
            $analyte_name = hex2bin(explode('20', $segment)[0]);
            $analyte = Analyte::where('name', $analyte_name)->first();
            $analyte_id = $analyte ? $analyte->analyte_id : 'N/A';
            $result_record = $this->extractResultAndUnit(hex2bin($segment));
            LOG::channel('biomaxima_test_log')->info(' -> Result record: ' . json_encode($result_record));

            $result_data['lab_id'] = env('LAB_ID');
            $result_data['barcode'] = $this->barcode;
            $result_data['analyte_id'] = $analyte_id;
            $result_data['analyte_name'] = $analyte_name;
            $result_data['result'] = ltrim($result_record['result']);
            $result_data['unit'] = $result_record['unit'];

            $this->results[] = $result_data;
        }
    }

    private function saveResults(): bool
    {
        foreach ($this->results as $result_data) {
            $saved_results = $this->resultService->createResult($result_data);
            if (!$saved_results) {
                return false;
            }
        }

        return true;
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
        Log::channel('biomaxima_test_log')->error(' Reconnecting...');
        echo "Reconnecting...\n";
        if ($this->socket) {
            socket_close($this->socket);
        }
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP); // Recreate socket

        while (!$this->connect()) {
            sleep(10);
        }
    }
//        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
//        $port = 9999;

//        $ip = '127.0.0.1';

    public function connect(): bool
    {
//        $ip = '192.168.1.111';
//        $port = 11111;

        $ip = '192.168.0.111';
        $port = 12000;

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            $errorMessage = socket_strerror(socket_last_error($this->socket));
            echo "Socket connection failed: $errorMessage\n";
            Log::channel('biomaxima_test_log')->error(" -> Socket connection failed. Error: " . $errorMessage);
            return false;
        }
        echo "Connection established\n";
        Log::channel('biomaxima_test_log')->debug(' -> Connection to analyzer established');
        return true;
    }

    private function extractResultAndUnit(string $segment): array
    {
        // Define a mapping of analyte names to their specific patterns
        $analytePatterns = [
            'LEU' => '/[><=]*\s*\d{1,3}[a-zA-Z\/]+/',
            'KET' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'NIT' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'URO' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'BIL' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'GLU' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'PRO' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'SG' => '/[><=]*\d{1,3}\.\d{3}/',
            'pH' => '/[><=]*\s*\d{1,3}(\.\d+)?/', // Simplified pattern to match the format without specific "pH" check
            'BLD' => '/[><=]*\s*\d{1,3}[a-zA-Z\/]+/', // Unified pattern with LEU
            'Vc' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'MA' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'Ca' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'CR' => '/[><=]*\s*\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
            'ACR' => '/[><=]*\d{1,3}(\.\d+)?~\d{1,3}(\.\d+)?\s*[a-zA-Z\/]+/',
        ];

        // Extract analyte name
        $analyteName = substr($segment, 0, strpos($segment, ' '));

        // Find the pattern for the analyte name
        $pattern = $analytePatterns[$analyteName] ?? null;

        if ($pattern && preg_match($pattern, $segment, $matches)) {
            $result_unit = $matches[0];

            // Extract the result including comparison symbols
            preg_match('/[><=~]*\s*\d{1,3}(\.\d+)?/', $result_unit, $resultMatch);
            $result = $resultMatch[0] ?? '';

            // Extract the unit
            $unit = trim(preg_replace('/[\d.><=~\s]/', '', $result_unit));

            return ['result' => $result, 'unit' => $unit];
        }

        // Return empty values if no match is found
        return ['result' => '', 'unit' => ''];
    }

    private function resetFunction(): void
    {
        $this->barcode = '';
        $this->msg = '';
        $this->results = [];
    }

}

<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Models\Analyte;
use App\Models\Order;
use App\Models\Result;
use App\Models\Test;
use Illuminate\Support\Facades\Log;

class MindrayAsClient
{
    public const VT = HexCodes::VT->value;
    public const FS = HexCodes::FS->value;
    public const CR = HexCodes::CR->value;

    private static ?MindrayAsClient $instance = null;
    private $server_socket;
    private $client_socket;
    private string $barcode = '';
    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        $this->resultService = $resultService;
        $this->createServerSocket();
    }

    public static function getInstance(ResultServiceInterface $resultService): MindrayAsClient
    {
        if (self::$instance === null) {
            self::$instance = new MindrayAsClient($resultService);
        }
        return self::$instance;
    }

    private function createServerSocket(): void
    {
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->server_socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

//        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
//        $port = 9999;

//        $ip = '62.80.253.55'; // public address

//        $ip = '127.0.0.1';
//        $port = 12000;

// original ip 192.168.1.247 // from Sysmex

    public function start(): bool
    {
//    connection parameters in laboratory
//        $ip = '192.168.1.112';
//        $port = 6669;

        $ip = '192.168.0.111';
        $port = 12000;

        if (!$this->bindSocket($ip, $port) || !$this->listenOnSocket()) {
            return false;
        }

        $this->acceptClientConnection();
        return true;
    }

    private function bindSocket(string $ip, int $port): bool
    {
        if (socket_bind($this->server_socket, $ip, $port) === false) {
            echo "Socket bind failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }
        return true;
    }

    private function listenOnSocket(): bool
    {
        if (socket_listen($this->server_socket, 5) === false) {
            echo "Socket listen failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }
        echo "Socket server started\n";
        return true;
    }

    private function acceptClientConnection(): void
    {
        $this->client_socket = socket_accept($this->server_socket);
        if ($this->client_socket === false) {
            echo "Socket accept failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return;
        }

        socket_getpeername($this->client_socket, $client_ip);
        echo "Client connected, IP: $client_ip\n";
    }

    public function process(): void
    {
        while (true) {
            if ($this->isClientDisconnected()) {
                echo "Client socket error\n";
                $this->handleClientDisconnection();
                break;
            }

            $inc = @socket_read($this->client_socket, 16384);
            LOG::channel('mindray_log')->info(" -> Received string: \n" . $inc);
            LOG::channel('mindray_log')->info(" -> Received hex: \n" . bin2hex($inc));

            if ($inc === false) {
                echo "Socket read failed: " . socket_strerror(socket_last_error($this->client_socket)) . "\n";
                $this->handleClientDisconnection();
                break;
            }

            $inc = bin2hex($inc);

            // Validate the hex string
            if (!ctype_xdigit($inc) || strlen($inc) % 2 != 0) {
                echo "Invalid hex string received: $inc\n";
                $this->handleClientDisconnection();
                break;
            }

            if (!$this->checkIfFullMessageReceived($inc)) {
                echo "Full message not received\n";
                $this->sendNACK();
                continue;
            }

            $inc = $this->removeControlCharacters($inc);

            if ($inc) {
                $message_control_id = $this->getMessageControlId($inc);
                echo "Message control ID: $message_control_id\n";

                if ($this->isQcMessage($inc)) {
                    $this->handleQCMessage($message_control_id);
                } else {
                    $this->handleIncomingMessage($inc);
                }
            }
        }
    }

    private function isClientDisconnected(): bool
    {
        return socket_get_option($this->client_socket, SOL_SOCKET, SO_ERROR) !== 0;
    }

    private function handleClientDisconnection(): void
    {
        $this->reconnect();
    }

    private function reconnect(): void
    {
        Log::channel('mindray_log')->error(' -> Waiting for client to reconnect ...');
        socket_close($this->client_socket);
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

    private function checkIfFullMessageReceived(string $inc): bool
    {
        return str_starts_with(strtoupper($inc), '0B') && str_ends_with(strtoupper($inc), '1C0D');
    }

    private function removeControlCharacters(string $inc): array|string
    {
        return substr($inc, 2, -6);
    }

    private function getMessageControlId(string $inc): string
    {
        foreach ($this->getMessageSegments($inc) as $segment) {
            if (str_starts_with($segment, 'MSH')) {
                return explode('|', $segment)[9];
            }
        }
        return '00000000';
    }

    private function getMessageSegments($inc): array
    {
        // Split the hexadecimal string into segments by "0D"
        $hexSegments = explode("0D", strtoupper($inc));

        // Convert each hexadecimal segment to binary
        return array_map(function($segment) {
            return hex2bin($segment);
        }, $hexSegments);
    }

    private function isQcMessage(string $inc): bool
    {
        $header = $this->getMessageSegments($inc)[0];
        return explode('|', $header)[10] === 'Q';
    }

    private function handleQCMessage(string $message_control_id): void
    {
        echo "QC message received\n";
        $this->sendACK($message_control_id, 'Q');
    }

    private function sendNACK($message_control_id = '00000000', string $type = 'P'): void
    {
        $nack_message = "MSH|^~\\&|LIS||||" . date('YmdHis') . "||ACK^A01|" . $message_control_id . "|" . $type . "|2.3.1\rMSA|AR|" . $message_control_id . "\r";
        $wrapped_nack_message = self::VT . $nack_message . self::FS . self::CR;
        socket_write($this->client_socket, $wrapped_nack_message, strlen($wrapped_nack_message));
    }

    private function sendACK(string $message_control_id, string $type = 'P'): void
    {
        $ack_message = "MSH|^~\\&|LIS||||" . date('YmdHis') . "||ACK^R01|" . $message_control_id . "|" . $type . "|2.3.1\rMSA|AA|" . $message_control_id . "\r";
        $wrapped_ack_message = self::VT . $ack_message . self::FS . self::CR;
        socket_write($this->client_socket, $wrapped_ack_message, strlen($wrapped_ack_message));
    }

    private function handleIncomingMessage(string $inc): void
    {
        $segments = $this->getMessageSegments($inc);
        $message_type = $this->getMessageType($segments[0]);
        echo "Message type: $message_type\n";

        if ($message_type === 'ORU') {
            echo "Result message received\n";
            $this->handleResult($segments);
        } elseif ($message_type === 'ORM') {
            echo "Order message received\n";
            $this->handleOrderRequest($segments);
        }

        $this->sendACK($this->getMessageControlId($inc));
    }

    private function getMessageType(mixed $header): string
    {
        return explode('^', explode('|', $header)[8])[0];
    }

    private function handleResult(array $segments): void
    {
        $message_control_id = '00000000';
        $this->barcode = '';

        foreach ($segments as $segment) {
            Log::channel('mindray_log')->info(' -> ' . $segment);
            echo "Segment: $segment\n";
            if (str_starts_with($segment, 'MSH')) {
                $message_control_id = explode('|', $segment)[9];
                echo "Message control ID: $message_control_id\n";
            }

            if (str_starts_with($segment, 'OBR')) {
                $this->barcode = explode('|', $segment)[3];
                echo "Barcode: $this->barcode\n";
            }

            if (str_starts_with($segment, 'OBX')) {
                if ($this->isResultSegment($segment)) {
                    echo "Processing OBX result segment...\n";
                    $this->processOBXSegment($segment);
                }
            }
        }
    }

    private function processOBXSegment(string $segment): void
    {
        $fields = explode('|', $segment);

        $test_item_mark = $fields[3] ?? '';
        $analyte_name = explode('^', $test_item_mark)[1] ?? '';
        $result_value = $fields[5] ?? '';
        $unit = $fields[6] ?? '';
        $analyte_id = $this->getAnalyteID($analyte_name);

        echo "Analyte name: $analyte_name\n";
        echo "Analyte ID: $analyte_id\n";
        echo "Result value: $result_value\n";
        echo "Unit: $unit\n";

        $result_data = [
            'lab_id' => env('LAB_ID'),
            'barcode' => $this->barcode,
            'analyte_name' => $analyte_name,
            'analyte_id' => $analyte_id,
            'result' => $result_value,
            'unit' => $unit,
            'original_string' => $segment,
        ];

        $this->resultService->createResult($result_data);
    }

    private function getAnalyteID(string $analyte_name): ?string
    {
        if (empty($analyte_name)) {
            return null;
        }

        $analyte = Analyte::where('name', $analyte_name)
            ->where('analyte_id', '!=', 'N/A')
            ->first();
        if ($analyte) {
            return $analyte->analyte_id;
        }

        return 'N/A';
    }

    // TODO: Implement the handleOrderRequest method if necessary

    private function handleOrderRequest(array $segments): void
    {
        $message_control_id = '00000000';
        $this->barcode = '';

        foreach ($segments as $segment) {
            if (str_starts_with($segment, 'MSH')) {
                $message_control_id = explode('|', $segment)[9];
                $message_processing_id = explode('|', $segment)[10];
            }

            if (str_starts_with($segment, 'ORC')) {
                $this->barcode = explode('|', $segment)[3];
                break;
            }
        }

        // TODO: Find the order by barcode
        // this is incorrect as there might be more orders with the same barcode
        $order = Order::where('test_barcode', $this->barcode)->first()
            ?? Order::where('order_barcode', $this->barcode)->first();

        if ($order) {
            $this->sendOrderInformation($message_control_id, $message_processing_id, $order->id);
        } else {
            $this->sendNACK($message_control_id);
        }

        $this->sendACK($message_control_id);
    }

    private function sendOrderInformation(string $message_control_id, string $message_processing_id, $order_id): void
    {
        // Placeholder: Implement order information sending
        $message = "MSH|^~\\&|BC-5380|Mindray|||20080617143943||ORR^R02|$message_control_id|$message_processing_id|2.3.1||||||UNICODE\r"
            . "MSA|AA|$message_control_id\r"
            . "ORC|AF|$this->barcode|||\r"
            . "OBR|1|SampleID1||||20060506||||tester|||Diagnose content....|20060504||||||||20080821||HM||||Validator||||Operator";

        $wrapped_message = self::VT . $message . self::FS . self::CR;
        socket_write($this->client_socket, $wrapped_message, strlen($wrapped_message));
    }

    private function isResultSegment(mixed $segment): bool
    {
        return (explode('|', $segment)[2] === 'NM') && !str_contains($segment, 'Scattergram') && !str_contains($segment, 'Histogram');
    }
}

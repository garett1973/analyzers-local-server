<?php

namespace App\Libraries\Analyzers\Default;

use App\Enums\HexCodes;
use App\Models\Order;
use App\Models\Result;
use App\Models\Test;
use Illuminate\Support\Facades\Log;

class DefaultHL7Server
{
    public const VT = HexCodes::VT->value;
    public const FS = HexCodes::FS->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private static ?DefaultHL7Server $instance = null;
    private $server_socket;
    private $client_socket;
    private string $barcode = '';

    public function __construct()
    {
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->server_socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(): DefaultHL7Server
    {
        if (self::$instance === null) {
            self::$instance = new DefaultHL7Server();
        }

        return self::$instance;
    }

    public function start(): bool
    {
//        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
//        $port = 9999;

//        $ip = '62.80.253.55'; // public address

//        $ip = '127.0.0.1';
//        $port = 12000;

        $ip = '192.168.0.111';
        $port = 9999;

        // Create socket
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->server_socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
            return false;
        }

        // Bind socket
        if (socket_bind($this->server_socket, $ip, $port) === false) {
            echo "Socket bind failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }

        // Listen on socket
        if (socket_listen($this->server_socket, 5) === false) {
            echo "Socket listen failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }

        echo "Socket server started on $ip:$port\n";

        // Accept client connection
        $this->client_socket = socket_accept($this->server_socket);
        if ($this->client_socket === false) {
            echo "Socket accept failed: " . socket_strerror(socket_last_error($this->server_socket)) . "\n";
            return false;
        }

        echo "Client connected\n";
        socket_getpeername($this->client_socket, $client_ip);
        echo "Client IP: $client_ip\n";

        return true;
    }

    public function process(): void
    {
        while (true) {
            // Check if the client socket is still connected
            if ($this->isClientDisconnected()) {
                echo "Client socket error\n";
                $this->handleClientDisconnection();
                break;
            }

            $inc = socket_read($this->client_socket, 8192);
//            echo "Received hex: $inc\n";

            if ($inc === false) {
                echo "Socket read failed: " . socket_strerror(socket_last_error($this->client_socket)) . "\n";
                $this->handleClientDisconnection();
                break;
            }

            // Validate the hex string
            if (!ctype_xdigit($inc) || strlen($inc) % 2 != 0) {
                echo "Invalid hex string received: $inc\n";
                $this->handleClientDisconnection();
                break;
            }

            $message_received = $this->checkIfFullMessageReceived($inc);
            if (!$message_received) {
                $this->sendNACK();
                continue;
            }

            $inc = $this->removeControlCharacters($inc);
//            echo "Message without control characters: $inc\n";

            // Convert hex to binary
            $inc = hex2bin($inc);
            if ($inc === false) {
                echo "Hex to binary conversion failed...\n";
                $this->handleClientDisconnection();
                break;
            }

            // Replace <CR> with actual newline character
            $inc = str_replace("\x0D", "\n", $inc);
//            echo "Message in binary: $inc\n";

            if ($inc) {
                $message_control_id = $this->getMessageControlId($inc);

                $qc_message = $this->isQcMessage($inc);
                if ($qc_message) {
                    $this->handleQCMessage($message_control_id);
                } else {
                    $this->handleIncomingMessage($inc);
                }
            }
        }
    }


    private function isClientDisconnected(): bool
    {
//        echo "Checking client socket\n";
        return socket_get_option($this->client_socket, SOL_SOCKET, SO_ERROR) !== 0;
    }

    private function handleClientDisconnection(): void
    {
        // Implement reconnection logic or cleanup here
        $this->reconnect();
    }

    private function reconnect(): void
    {
        Log::channel('default_hl_server_log')->error(now() . ' -> Waiting for client to reconnect ...');
        echo "Waiting for client to reconnect...\n";

        // Close the current client socket
        socket_close($this->client_socket);

        // Wait for a new client connection
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
        // Check if the message is wrapped with <VT> and <FS><CR>
        if (!str_starts_with(strtoupper($inc), '0B') || !str_ends_with(strtoupper($inc), '1C0D')) {
            echo "Received incomplete message\n";
            return false;
        }

        echo "Received complete message\n";
        return true;
    }

    private function sendNACK($message_control_id = '00000000', string $type = 'P'): void
    {
        $nack_message = "MSH|^~\\&|LIS||||" . date('YmdHis') . "||ACK^A01|" . $message_control_id . "|" . $type . "|2.3.1\rMSA|AR|" . $message_control_id . "\r";
        $wrapped_nack_message = self::VT . $nack_message . self::FS . self::CR;
        socket_write($this->client_socket, $wrapped_nack_message, strlen($wrapped_nack_message));
    }

    private function removeControlCharacters(string $inc): array|string
    {
        // remove first 2 and last 6 characters
        return substr($inc, 2, -6);
    }

    private function getMessageControlId(string $inc): string
    {
        $segments = $this->getMessageSegments($inc);
        $message_control_id = '00000000';
        foreach ($segments as $segment) {
            if (str_starts_with($segment, 'MSH')) {
                $message_control_id = explode('|', $segment)[9];
//                echo "Message control ID: $message_control_id\n";
                break;
            }
        }

        return $message_control_id;
    }

    private function getMessageSegments($inc): array
    {
        return explode("\n", $inc);
    }

    private function isQcMessage(string $inc): bool
    {
        $header = $this->getMessageSegments($inc)[0];
        return explode('|', $header)[10] === 'Q';
    }

    private function handleQCMessage(string $message_control_id): void
    {
        $this->sendACK($message_control_id, 'Q');
    }

    private function sendACK(string $message_control_id, string $type = 'P'): void
    {
        $ack_message = "MSH|^~\\&|LIS||||" . date('YmdHis') . "||ACK^R01|" . $message_control_id . "|" . $type . "|2.3.1\rMSA|AA|" . $message_control_id . "\r";
        $wrapped_ack_message = self::VT . $ack_message . self::FS . self::CR;
        $hex_ack_message = bin2hex($wrapped_ack_message);
        socket_write($this->client_socket, $hex_ack_message, strlen($hex_ack_message));
    }

    private function handleIncomingMessage(string $inc): void
    {
        $segments = $this->getMessageSegments($inc);
        $message_type = $this->getMessageType($segments[0]);
        echo "Message type: $message_type\n";
        if ($message_type === 'ORU') {
            $this->handleResult($segments);
        } else {
            if ($message_type === 'ORM') {
                $this->handleOrderRequest($segments);
            }
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
        $lis_code = '';
        foreach ($segments as $segment) {
            Log::channel('default_hl_server_log')->info(now() . ' -> ' . $segment);
            if (str_starts_with($segment, 'MSH')) {
                $message_control_id = explode('|', $segment)[9];
            }

            if (str_starts_with($segment, 'OBR')) {
                $this->barcode = explode('|', $segment)[3];
                echo "Barcode: $this->barcode\n";
            }

            if (str_starts_with($segment, 'OBX')) {
                $test_item_mark = explode('|', $segment)[3];
                $analyte_code = explode('^', $test_item_mark)[1];
                $result_value = explode('|', $segment)[5];
                $unit = explode('|', $segment)[6];
                $reference_range = explode('|', $segment)[7];

                if (isset($analyte_code) && $analyte_code != '') {
                    $sub_test_id = Test::where('name', $analyte_code)->first()->sub_test_id ?? null;
                    $test_id = Test::where('name', $analyte_code)->first()->test_id ?? null;
                    $lis_code = $sub_test_id != null ? $test_id . "-" . $sub_test_id : $test_id;
                }

                echo "Analyte code: $analyte_code\n";
                echo "LIS code: $lis_code\n";
                echo "Result value: $result_value\n";
                echo "Unit: $unit\n";
                echo "Reference range: $reference_range\n";

                $result = new Result(
                    [
                        'barcode' => $this->barcode,
                        'analyte_code' => $analyte_code,
                        'lis_code' => $lis_code,
                        'result' => $result_value,
                        'unit' => $unit,
                        'reference_range' => $reference_range,
                        'original_string' => $segment,
                    ]
                );

                $result->save();
            }
        }

        $this->sendACK($message_control_id);
    }

    private function handleOrderRequest(array $segments): void
    {
        $message_control_id = '00000000';
        $this->barcode = '';
        $order = null;
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

        if ($this->barcode !== '') {
            $order = Order::where('test_barcode', $this->barcode)->first() ??
                Order::where('order_barcode', $this->barcode)->first() ??
                null;
        }

        if ($order) {
            $order_id = $order->id;
            $this->sendOrderInformation($message_control_id, $message_processing_id, $order_id);
        } else {
            $this->sendNACK($message_control_id);
        }

        $this->sendACK($message_control_id);
    }

    private function sendOrderInformation(string $message_control_id, string $message_processing_id, $order_id): void
    {
        // todo: implement order information sending

//        $message = "MSH|^~\\&|BC-5380|Mindray|||20080617143943||ORR^R02|" . $message_control_id . "|" . $message_processing_id . "|2.3.1||||||UNICODE\r" .
//            "MSA|AA|" . $message_control_id . "\r" .
//            "ORC|AF|" . $this->barcode . "|||\r" .
//            "OBR|1|SampleID1||||20060506||||tester|||Diagnose content....|20060504||||||||20080821||HM||||Validator||||Operator";
//
//        $wrapped_message = self::VT . $message . self::FS . self::CR;
//        socket_write($this->client_socket, $wrapped_message, strlen($wrapped_message));
    }
}

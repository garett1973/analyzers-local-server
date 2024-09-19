<?php

namespace App\Libraries\Analyzers\Default;

use App\Enums\HexCodes;
use App\Models\Order;
use App\Models\Result;
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
    private bool $receiving = true;
    private bool $order_requested = false;
    private bool $order_found = false;
    private string $order_record = '';
    private string $header = '';
    private string $patient = '';
    private string $terminator = '';
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

            while ($this->receiving) {
                $inc = socket_read($this->client_socket, 1024);

                if ($inc === false) {
                    echo "Socket read failed: " . socket_strerror(socket_last_error($this->client_socket)) . "\n";
                    $this->handleClientDisconnection();
                    break;
                }

                if ($inc) {
                    $message_received = $this->checkIfFullMessageReceived($inc);
                    $message_control_id = $this->getMessageControlId($inc);
                    if ($message_received) {
                        $qc_message = $this->isQcMessage($inc);
                        if ($qc_message) {
                            $this->handleQCMessage($message_control_id);
                        } else {
                            $this->handleIncomingMessage($inc);
                        }
                    } else {
                        $this->sendNACK($message_control_id);
                    }
                }
            }
        }
    }

    private function isClientDisconnected(): bool
    {
        echo "Checking client socket\n";
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
        $rawMessage = bin2hex($inc);
        // Check for start and end block characters
        if ($rawMessage[0] === "\x0B" && str_ends_with($rawMessage, "\x1C\x0D")) {
            return true;
        }

        return false;
    }

    private function getMessageControlId(string $inc): string
    {
        $segments = $this->getMessageSegments($inc);
        $message_control_id = '00000000';
        foreach ($segments as $segment) {
            if (str_starts_with($segment, 'MSH')) {
                $message_control_id = explode('|', $segment)[9];
                break;
            }
        }

        return $message_control_id;
    }

    private function getMessageSegments($inc): array
    {
        return explode('\r', $inc);
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
        socket_write($this->client_socket, $wrapped_ack_message, strlen($wrapped_ack_message));
    }

    private function handleIncomingMessage(string $inc): void
    {
        $segments = $this->getMessageSegments($inc);
        $message_control_id = $this->getMessageControlId($inc);
        $message_type = $this->getMessageType($segments[0]);

        if ($message_type === 'ORU') {
            $this->handleResult($segments, $message_control_id);
        } else {
            if ($message_type === 'ORM') {
                $this->handleOrderRequest($segments, $message_control_id);
            }
        }
    }

    private function handleResult(array $segments, string $message_control_id): void
    {
        foreach ($segments as $segment) {
            Log::channel('default_hl_server_log')->info(now() . ' -> ' . $segment);

            if (str_starts_with('OBR', $segment)) {
                $this->barcode = explode('|', $segment)[3];
            }

            if (str_starts_with('OBX', $segment)) {
                $analyte_code = explode('|', $segment)[3];
                $result_value = explode('|', $segment)[5];
                $unit = explode('|', $segment)[6];
                $reference_range = explode('|', $segment)[7];
            }

            $order = Order::where('test_barcode', $this->barcode)->first() ?? Order::where('order_barcode', $this->barcode)->first();

            $result = new Result(
                [
                    'order_id' => $order ? $order->id : null,
                    'barcode' => $this->barcode,
                    'analyte_code' => $analyte_code,
                    'result' => $result_value,
                    'unit' => $unit,
                    'reference_range' => $reference_range,
                ]
            );


        }

        $this->sendACK($message_control_id);
    }

    private function handleOrderRequest(array $segments, string $message_control_id): void
    {
        $this->barcode = '';
        $order = null;
        foreach ($segments as $segment) {
            if (str_starts_with('ORC', $segment)) {
                $this->order_requested = true;
                $this->barcode = explode('|', $segment)[3];
                break;
            }
        }

        if ($this->barcode !== '') {
            $order = Order::where('test_barcode', $this->barcode)->first();
            if (!$order) {
                $order = Order::where('order_barcode', $this->barcode)->first();
            }
        }

        if ($order) {
            $order_id = $order->id;
            $this->sendOrderInformation($message_control_id, $order_id);
        } else {
            $this->sendNACK($message_control_id);
        }

        $this->sendACK($message_control_id);
    }

    private function sendOrderInformation(string $message_control_id, $order_id)
    {
        $message = "MSH|^~\\&|BC-5380|Mindray|||20080617143943||ORR^R02|1|P|2.3.1||||||UNICODE\r" .
            "MSA|AA|" . $message_control_id . "\r" .
            "ORC|Rf|" . $this->barcode . "|||CM\r" .
            "OBR|1|SampleID1||||20060506||||tester|||Diagnose content....|20060504||||||||20080821||HM||||Validator||||Operator";
    }

    private function sendNACK($message_control_id = '00000000', string $type = 'P'): void
    {
        $nack_message = "MSH|^~\\&|LIS||||" . date('YmdHis') . "||ACK^A01|" . $message_control_id . "|" . $type . "|2.3.1\rMSA|AR|" . $message_control_id . "\r";
        $wrapped_nack_message = self::VT . $nack_message . self::FS . self::CR;
        socket_write($this->client_socket, $wrapped_nack_message, strlen($wrapped_nack_message));
    }

    private function getMessageType(mixed $header): string
    {
        return explode('^', explode('|', $header)[8])[0];
    }


}


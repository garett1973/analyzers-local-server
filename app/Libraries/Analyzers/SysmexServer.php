<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Models\Analyte;
use App\Models\Order;
use App\Models\Result;
use App\Models\Test;
use Exception;
use Illuminate\Support\Facades\Log;

class SysmexServer
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private static ?SysmexServer $instance = null;
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
    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        $this->resultService = $resultService;
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->server_socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(ResultServiceInterface $resultService): SysmexServer
    {
        if (self::$instance === null) {
            self::$instance = new SysmexServer($resultService);
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
//                    $inc = bin2hex($inc); // Convert to hex - not sure if this is necessary for all the analyzers
                    $this->handleIncomingMessage($inc);
                }
            }

            echo "Receiving stopped, order was requested\n";
            $this->handleOrderStatus();
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

    private function handleIncomingMessage(string $inc): void
    {
        switch ($inc) {
            case self::ENQ:
                $this->handleEnq();
                break;
            case self::EOT:
                $this->receiving = $this->handleEot();
                break;
            default:
                $header = $this->getDataMessageFirstSegment($inc);
                if (!$header) {
                    $this->sendNAK();
                    break;
                }
                $this->handleMessage($header, $inc);
                break;
        }
    }

    private function handleOrderStatus(): void
    {
        if (!$this->order_found) {
            $this->handleOrderNotFound();
        } else {
            $this->sendOrderRecord();
        }
    }

    private function handleMessage(string $header, string $inc): void
    {
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
            case 'Q':
                $this->handleOrderRequest($inc);
                break;
            case 'R':
                $this->handleResult($inc);
                break;
            case 'C':
                $this->handleComment($inc);
                break;
            case 'L':
                $this->handleTerminator($inc);
                break;
            default:
                $this->sendNAK();
                break;
        }
    }

    private function handleEnq(): void
    {
        echo "ENQ received\n";
        Log::channel('sysmex_server_log')->info(now() . ' -> ENQ received');
        $this->sendACK();
    }

    private function handleEot(): bool
    {
        echo "EOT received\n";
        Log::channel('sysmex_server_log')->info(now() . ' -> EOT received');
        $this->barcode = '';
        if ($this->order_requested) {
            return false;
        }

        return true;
    }

    private function sendACK(): void
    {
        socket_write($this->client_socket, self::ACK, strlen(self::ACK));
        Log::channel('sysmex_server_log')->info(now() . ' -> ACK sent');
        echo "ACK sent\n";
    }

    private function sendNAK(): void
    {
        socket_write($this->client_socket, self::NAK, strlen(self::NAK));
        $this->barcode = '';
        $this->order_requested = false;
        $this->order_found = false;
        $this->receiving = true;
        Log::channel('sysmex_server_log')->info(now() . 'NAK sent');
        echo "NAK sent\n";
    }

    private function sendENQ(): void
    {
        socket_write($this->client_socket, self::ENQ, strlen(self::ENQ));
        Log::channel('sysmex_server_log')->info(now() . 'ENQ sent');
        echo "ENQ sent\n";
    }

    private function sendEOT(): void
    {
        $this->order_record = '';
        $this->order_requested = false;
        $this->receiving = true;
        $this->barcode = '';
        socket_write($this->client_socket, self::EOT, strlen(self::EOT));
        Log::channel('sysmex_server_log')->info('EOT sent at ' . now());
        echo "EOT sent\n";
    }

    private function getDataMessageFirstSegment($inc): false|string
    {
        $inc = $this->processMessage($inc);
        if (!$inc) {
            return false;
        }

        // get first segment of the message
        $first_segment = explode('|', $inc)[0];
        // remove all characters that are not letters from the first segment
        return preg_replace('/[^a-zA-Z]/', '', $first_segment);
    }

    private function handleHeader(string $inc): void
    {
        Log::channel('sysmex_server_log')->info(now() . ' -> Header received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_server_log')->info('Header string: ' . $inc);
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function handlePatient(string $inc): void
    {
        Log::channel('sysmex_server_log')->info(now() . ' -> Patient received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_server_log')->info('Patient string: ' . $inc);
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        Log::channel('sysmex_server_log')->info(now() . ' -> Order received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_server_log')->info('Order string: ' . $inc);
        echo "Order data received: $inc\n";

        $this->barcode = explode('|', $inc)[3];
        $this->barcode = preg_replace('/[^0-9]/', '', $this->barcode);
        echo "Barcode from order string in results: $this->barcode\n";

        $this->sendACK();
    }

    private function handleOrderRequest(string $inc): void
    {
        $this->order_requested = true;
        Log::channel('sysmex_server_log')->info(now() . ' -> Order request received: ' . $inc);

        // Clean the incoming message
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_server_log')->info('Order request string: ' . $inc);
        echo "Order request received: $inc\n";

        // Extract and clean the barcode
        $parts = explode('|', $inc);
        $this->barcode = preg_replace('/[^0-9]/', '', $parts[2]);
        echo "Barcode from request string in order request: $this->barcode\n";

        // Retrieve the order record
        $this->order_record = $this->getOrderString();
        if ($this->order_record) {
            $this->order_found = true;
            echo "Order string: $this->order_record\n";
        } else {
            echo "Order not found\n";
        }

        // Send acknowledgment
        $this->sendACK();
    }

    private function handleComment(string $inc): void
    {
        Log::channel('sysmex_server_log')->info(now() . ' -> Comment received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_server_log')->info('Comment string: ' . $inc);
        echo "Comment received: $inc\n";
        $this->sendACK();
    }

    private function handleTerminator(string $inc): void
    {
        Log::channel('sysmex_server_log')->info(now() . 'Terminator received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_server_log')->info('Terminator string: ' . $inc);
        echo "Terminator received: $inc\n";
        $this->sendACK();
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
        $inc = substr($inc, 2);
        return substr($inc, 0, -8);
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

        echo "Checksum OK\n";
        return true;
    }

    private function calculateChecksum(string $inc): string
    {
        $hexArray = str_split($inc, 2);
        $checksum = array_reduce($hexArray, static function($carry, $hex) {
            return $carry + hexdec($hex);
        }, 0);

        $checksum &= 0xFF;
        return strtoupper(dechex($checksum));
    }

    private function getOrderString(): ?string
    {
        $order = Order::where('test_barcode', $this->barcode)
            ->orWhere('order_barcode', $this->barcode)
            ->first();

        return $order->order_record ?? null;
    }

    private function handleResult(string $inc): void
    {
        Log::channel('sysmex_server_log')->info(now() . ' -> Result received: ' . $inc);

        // Clean the incoming message
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_server_log')->info('Result string: ' . $inc);
        echo "Result received: $inc\n";

        // Extract data from the cleaned message
        [, , $analyte_name, $result, $unit, $ref_range] = explode('|', $inc);

        // Remove leading "^" from LIS code
        $analyte_name = ltrim($analyte_name, "^");
        $analyte_name = explode('^', $analyte_name)[1];

        // Log extracted data
        echo "Analyte name: $analyte_name\n";
        echo "Result: $result\n";
        echo "Unit: $unit\n";
        echo "Reference range: $ref_range\n";

        // Save the result
        $result_saved = $this->saveResult($inc);

        // Handle the result saving outcome
        if ($result_saved) {
            Log::channel('sysmex_server_log')->info(now() . ' -> Result saved');
            $this->sendACK();
            echo "Result saved\n";
        } else {
            Log::channel('sysmex_server_log')->error(now() . ' -> Result save error');
            $this->sendNAK();
            echo "Result not saved\n";
        }
    }

    private function handleOrderNotFound(): void
    {
        echo "Order requested but not found\n";
        Log::channel('sysmex_server_log')->info(now() . 'Order requested but not found for barcode ' . $this->barcode);
        $this->sendENQ();
        $inc = socket_read($this->client_socket, 1024);
        if ($inc == self::ACK) {
            Log::channel('sysmex_server_log')->info(now() . 'ACK received');
            $this->sendEOT();
        }
    }

    private function sendOrderRecord(): void
    {
        $this->header = $this->prepareMessageString($this->getShortHeader());
        $this->patient = $this->prepareMessageString($this->getPatient());
        $this->order_record = $this->prepareMessageString($this->order_record);
        $this->terminator = $this->prepareMessageString($this->getTerminator());

        $this->sendENQ();
        $inc = socket_read($this->client_socket, 1024);
        if ($inc == self::ACK) {
            Log::channel('sysmex_server_log')->info(now() . ' -> ACK received');
            echo "ACK received\n";
            $this->sendMessages();
        }
    }

    private function prepareMessageString(string $message): string
    {
        $message = $message . self::CR . self::ETX;
        $checksum = $this->getChecksum($message);
        if (strlen($checksum) === 1) {
            $checksum = '0' . $checksum;
        }

        echo "Checksum: $checksum\n";

        $message .= $checksum;
        $message .= self::CR . self::LF;
        $message = self::STX . $message;
        echo "Message to sent: $message\n";
        return strtoupper(bin2hex($message));
    }

    private function getChecksum(string $message): string
    {
        $checksum = 0;
        for ($i = 0, $iMax = strlen($message); $i < $iMax; $i++) {
            $checksum += ord($message[$i]);
        }
        $checksum %= 256;
        $checksum &= 0xFF;
        return strtoupper(dechex($checksum));
    }

    private function getShortHeader(): string
    {
        return 'H|\^&';
    }

    private function getPatient(): string
    {
        return 'P|1';
    }

    private function getTerminator(): string
    {
        return 'L|1|F';
    }

    private function sendMessages(): void
    {
        try {
            $this->sendData($this->header);
            $this->header = '';

            if ($this->order_record != '') {
                $this->sendData($this->patient);
                $this->patient = '';

                $this->sendData($this->order_record);
                $this->order_record = '';

                $this->sendData($this->terminator);
                $this->terminator = '';
            }

            $this->sendEOT();
        } catch (Exception $e) {
            $this->reconnect();
        }
    }

    /**
     * @throws Exception
     */
    private function sendData(string $data): void
    {
        socket_write($this->client_socket, $data, strlen($data));
        $inc = socket_read($this->client_socket, 1024);
        if ($inc != self::ACK) {
            throw new Exception('ACK not received');
        }
    }

    private function cleanMessage(string $inc): string
    {
        // Remove STX from the start, LF, CR, checksum, ETX from the end
        $inc = substr($inc, 2, -10);
        return hex2bin($inc);
    }

    private function reconnect(): void
    {
        Log::channel('sysmex_server_log')->error(now() . ' -> Waiting for client to reconnect ...');
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

    private function saveResult(string $inc): bool
    {
        // Destructure the exploded string into variables
        [, , $analyte_name, $result_value, $unit, $ref_range] = explode('|', $inc);

        $analyte_name = ltrim($analyte_name, "^");
        $analyte_name = explode('^', $analyte_name)[1];

        // Find the analyte by analyte code
        $analyte = Analyte::where('name', $analyte_name)->first();
        $analyte_id = $analyte ? $analyte->analyte_id : null;

        // Create a new result
        $result_data = [
            'company_id' => env('COMPANY_ID'),
            'lab_id' => env('LAB_ID'),
            'barcode' => ltrim($this->barcode, '0'),
            'analyte_id' => $analyte_id ?? 'N/A',
            'analyte_name' => $analyte_name,
            'result' => $result_value,
            'unit' => $unit,
            'reference_range' => $ref_range,
            'original_string' => $inc,
        ];

        return $this->resultService->createResult($result_data);
    }

    private function handleTimeout(): void
    {
        echo "Timeout occurred\n";
    }
}


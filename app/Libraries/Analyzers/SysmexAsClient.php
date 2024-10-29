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

class SysmexAsClient
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private static ?SysmexAsClient $instance = null;
    private $server_socket;
    private $client_socket;
    private bool $receiving = true;
    private bool $order_requested = false;
    private bool $order_found = false;
    private string $order_record = '';
    private string $barcode = '';
    private array $results = [];
    private ResultServiceInterface $resultService;

    public function __construct(ResultServiceInterface $resultService)
    {
        $this->resultService = $resultService;
        $this->createServerSocket();
    }

    private function createServerSocket(): void
    {
        $this->server_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->server_socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(ResultServiceInterface $resultService): SysmexAsClient
    {
        if (self::$instance === null) {
            self::$instance = new SysmexAsClient($resultService);
        }
        return self::$instance;
    }

//        $ip = '85.206.48.46';
//        $ip = '192.168.1.111';
//        $port = 9999;

//        $ip = '62.80.253.55'; // public address

//        $ip = '127.0.0.1';
//        $port = 12000;

// original ip 192.168.1.247

    public function start(): bool
    {
        $ip = '192.168.1.112';
        $port = 6670;

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

    /**
     * @throws Exception
     */
    public function process(): void
    {
        while (true) {
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
                    $this->handleIncomingMessage($inc);
                }
            }
            echo "Receiving stopped, order was requested\n";
            $this->handleOrderStatus();
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
        Log::channel('sysmex_log')->error(' -> Waiting for client to reconnect ...');
        echo "Waiting for client to reconnect...\n";
        socket_close($this->client_socket);
        $this->acceptClientConnection();
        $this->process();
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

    /**
     * @throws Exception
     */
    private function handleOrderStatus(): void
    {
        $this->order_found ? $this->sendOrderRecord() : $this->handleOrderNotFound();
    }

    private function handleMessage(string $header, string $inc): void
    {
        $handlers = [
            'H' => 'handleHeader',
            'P' => 'handlePatient',
            'O' => 'handleOrder',
            'Q' => 'handleOrderRequest',
            'R' => 'handleResult',
            'C' => 'handleComment',
            'L' => 'handleTerminator',
        ];

        if (array_key_exists($header, $handlers)) {
            $this->{$handlers[$header]}($inc);
        } else {
            $this->sendNAK();
        }
    }

    private function handleEnq(): void
    {
        $this->logAndSend('ENQ received', 'ENQ sent', self::ACK);
    }

    private function handleEot(): bool
    {
        $this->logEvent('EOT received');
        $this->barcode = '';
        return !$this->order_requested;
    }

    private function sendACK(): void
    {
        $this->sendMessage(self::ACK, 'ACK sent');
    }

    private function sendNAK(): void
    {
        $this->resetOrderStatus();
        $this->sendMessage(self::NAK, 'NAK sent');
    }

    private function sendENQ(): void
    {
        $this->sendMessage(self::ENQ, 'ENQ sent');
    }

    private function sendEOT(): void
    {
        $this->resetOrderStatus();
        $this->sendMessage(self::EOT, 'EOT sent');
    }

    private function resetOrderStatus(): void
    {
        $this->barcode = '';
        $this->order_requested = false;
        $this->order_found = false;
        $this->receiving = true;
    }

    private function sendMessage(string $message, string $logMessage): void
    {
        socket_write($this->client_socket, $message, strlen($message));
        $this->logEvent($logMessage);
    }

    private function logAndSend(string $receiveLog, string $sendLog, string $message): void
    {
        $this->logEvent($receiveLog);
        $this->sendMessage($message, $sendLog);
    }

    private function logEvent(string $message): void
    {
        Log::channel('sysmex_log')->info(" -> $message");
        echo "$message\n";
    }

    private function getDataMessageFirstSegment(string $inc): false|string
    {
        $inc = $this->processMessage($inc);
        if (!$inc) {
            return false;
        }
        $first_segment = explode('|', $inc)[0];
        return preg_replace('/[^a-zA-Z]/', '', $first_segment);
    }

    private function handleHeader(string $inc): void
    {
        $this->logAndProcessMessage('Header', $inc);
    }

    private function handlePatient(string $inc): void
    {
        $this->logAndProcessMessage('Patient', $inc);
    }

    private function handleOrder(string $inc): void
    {
        $this->logAndProcessMessage('Order', $inc);
        $inc = $this->cleanMessage($inc);
        $this->barcode = preg_replace('/[^0-9]/', '', explode('^', explode('|', $inc)[3])[2]);
        echo "Barcode from order string in results: $this->barcode\n";
    }

    private function handleOrderRequest(string $inc): void
    {
        $this->order_requested = true;
        $this->logAndProcessMessage('Order request', $inc);
        $inc = $this->cleanMessage($inc);
        $this->barcode = preg_replace('/[^0-9]/', '', explode('|', $inc)[3]);
        echo "Barcode from request string in order request: $this->barcode\n";
        $this->order_record = $this->getOrderString();
        $this->order_found = !empty($this->order_record);
        echo $this->order_found ? "Order string: $this->order_record\n" : "Order not found\n";
    }

    private function handleComment(string $inc): void
    {
        $this->logAndProcessMessage('Comment', $inc);
    }

    private function handleTerminator(string $inc): void
    {
        $this->logAndProcessMessage('Terminator', $inc);
    }

    private function logAndProcessMessage(string $type, string $inc): void
    {
        Log::channel('sysmex_log')->info(" -> $type received: $inc");
        $inc = $this->cleanMessage($inc);
        Log::channel('sysmex_log')->info("$type string: $inc");
        echo "$type received: $inc\n";
        $this->sendACK();
    }

    private function processMessage(string $inc): ?string
    {
        $checksum = $this->extractChecksum($inc);
        $preparedInc = $this->prepareMessageForChecksum($inc);
        return $this->isChecksumValid($preparedInc, $checksum) ? $this->cleanMessage($inc) : null;
    }

    private function extractChecksum(string $inc): string
    {
        $inc = bin2hex($inc);
        $checksum = substr(substr($inc, 0, -4), -4);
        return strtoupper(hex2bin($checksum));
    }

    private function prepareMessageForChecksum(string $inc): string
    {
        $inc = bin2hex($inc);
        return substr(substr($inc, 2), 0, -8);
    }

    private function isChecksumValid(string $inc, string $checksum): bool
    {
        if ($checksum[0] == '0') {
            $checksum = substr($checksum, 1);
        }
        return $this->calculateChecksum($inc) === $checksum;
    }

    private function calculateChecksum(string $inc): string
    {
        $checksum = array_reduce(str_split($inc, 2), static fn($carry, $hex) => $carry + hexdec($hex), 0);
        return strtoupper(dechex($checksum & 0xFF));
    }

    private function getOrderString(): ?string
    {
        $order = Order::where('test_barcode', $this->barcode)->orWhere('order_barcode', $this->barcode)->first();
        return $order->order_record ?? null;
    }

    private function handleResult(string $inc): void
    {
        $cleanedMessage = $this->cleanMessage($inc);
        $this->logMessage('Result received', $cleanedMessage);

        if ($this->saveResult($cleanedMessage)) {
            $this->logAndRespond('Result saved', 'ACK');
        } else {
            $this->logAndRespond('Result save error', 'NAK');
        }
    }

    private function extractResultData(string $inc): array
    {
        $data = explode('|', $inc);
        $analyte_name = explode('^', ltrim($data[2], "^"))[1];
        return [$analyte_name, $data[3], $data[4], $data[5]];
    }

    private function logAndRespond(string $logMessage, string $responseType): void
    {
        $this->logEvent($logMessage);
        $responseType === 'ACK' ? $this->sendACK() : $this->sendNAK();
    }

    private function handleOrderNotFound(): void
    {
        $this->logEvent("Order requested but not found for barcode {$this->barcode}");
        $this->sendENQ();
        if (socket_read($this->client_socket, 1024) == self::ACK) {
            $this->logEvent('ACK received');
            $this->sendEOT();
        }
    }

    /**
     * @throws Exception
     */
    private function sendOrderRecord(): void
    {
        $this->prepareAndSendMessageSequence(
            [$this->getShortHeader(), $this->getPatient(), $this->order_record, $this->getTerminator()]
        );
    }

    /**
     * @throws Exception
     */
    private function prepareAndSendMessageSequence(array $messages): void
    {
        $this->sendENQ();
        if (socket_read($this->client_socket, 1024) == self::ACK) {
            $this->logEvent('ACK received');
            foreach ($messages as $message) {
                if (!empty($message)) {
                    $this->sendData($this->prepareMessageString($message));
                }
            }
            $this->sendEOT();
        }
    }

    private function prepareMessageString(string $message): string
    {
        $message .= self::CR . self::ETX;
        $checksum = str_pad($this->getChecksum($message), 2, '0', STR_PAD_LEFT);
        $message .= $checksum . self::CR . self::LF;
        return strtoupper(bin2hex(self::STX . $message));
    }

    private function getChecksum(string $message): string
    {
        $checksum = array_sum(array_map('ord', str_split($message))) % 256;
        return strtoupper(dechex($checksum & 0xFF));
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

    private function cleanMessage(string $inc): string
    {
        $inc = bin2hex($inc);
        return hex2bin(substr(substr($inc, 2), 0, -10));
    }

    private function saveResult(string $inc): bool
    {
        [$analyte_name, $result_value, $unit] = $this->extractResultData($inc);
        $analyte = Analyte::where('name', $analyte_name)->first();
        $analyte_id = $analyte ? $analyte->analyte_id : 'N/A';

        $result_data = [
            'lab_id' => env('LAB_ID'),
            'barcode' => ltrim($this->barcode, '0'),
            'analyte_id' => $analyte_id,
            'analyte_name' => $analyte_name,
            'result' => $result_value,
            'unit' => $unit,
            'original_string' => $inc,
        ];

        return $this->resultService->createResult($result_data);
    }

    private function logMessage(string $type, string $message): void
    {
        Log::channel('sysmex_log')->info(" -> $type: $message");
        echo "$type: $message\n";
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
        $this->logEvent("Data sent: $data");
    }
}


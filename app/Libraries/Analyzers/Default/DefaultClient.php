<?php

namespace App\Libraries\Analyzers\Default;

use App\Enums\HexCodes;
use App\Models\Order;
use App\Models\Result;
use App\Models\Test;
use Exception;
use Illuminate\Support\Facades\Log;

class DefaultClient
{
    public const ACK = HexCodes::ACK->value;
    public const NAK = HexCodes::NAK->value;
    public const ENQ = HexCodes::ENQ->value;
    public const STX = HexCodes::STX->value;
    public const ETX = HexCodes::ETX->value;
    public const EOT = HexCodes::EOT->value;
    public const CR = HexCodes::CR->value;
    public const LF = HexCodes::LF->value;

    private static ?DefaultClient $instance = null;
    private $socket;
    private $connection;
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
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(): DefaultClient
    {
        if (self::$instance === null) {
            self::$instance = new DefaultClient();
        }

        return self::$instance;
    }

    public function connect(): bool
    {
        $ip = '85.206.48.46';
//    $ip = '192.168.1.111';
        $port = 9999;

//    $ip = '127.0.0.1';
//    $port = 12000;

        // Attempt to connect to the socket server
        $this->connection = @socket_connect($this->socket, $ip, $port);

        if ($this->connection === false) {
            $errorMessage = socket_strerror(socket_last_error($this->socket));
            echo "Socket connection failed: $errorMessage\n";
            Log::channel('default_client_log')->error(now() . " -> Socket connection failed. Error: " . ": $errorMessage");
            return false;
        }

        echo "Connection established\n";
        Log::channel('default_client_log')->debug(now() . ' -> Connection to analyzer established');
        return true;
    }

    public function process(): void
    {
        while (true) {
            if ($this->connection) {
                $this->setSocketOptions();
                echo "Receiving: $this->receiving\n";

                while ($this->receiving) {
                    $inc = socket_read($this->socket, 1024);

                    if ($inc === false) {
                        $this->handleTimeout();
                        break;
                    }

                    if ($inc) {
                        $inc = bin2hex($inc); // Convert to hex - not sure if this is necessary for all the analyzers
                        $this->handleIncomingMessage($inc);
                    }
                }

                echo "Receiving stopped, order was requested\n";
                $this->handleOrderStatus();
            } else {
                $this->reconnect();
            }
        }
    }

    private function setSocketOptions(): void
    {
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 15, 'usec' => 0]);
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
                $this->processDataMessage($inc);
                break;
        }
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


    private function handleEnq(): void
    {
        echo "ENQ received\n";
        Log::channel('default_client_log')->info(now() . ' -> ENQ received');
        $this->sendACK();
    }

    private function handleEot(): bool
    {
        echo "EOT received\n";
        Log::channel('default_client_log')->info(now() . ' -> EOT received');
        if ($this->order_requested) {
            $this->barcode = '';
            return false;
        }
        $this->barcode = '';
        return true;
    }

    private function sendACK(): void
    {
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        Log::channel('default_client_log')->info(now() . ' -> ACK sent');
        echo "ACK sent\n";
    }

    private function sendNAK(): void
    {
        socket_write($this->socket, self::NAK, strlen(self::NAK));
        Log::channel('default_client_log')->info(now() . ' -> NAK sent');
        echo "NAK sent\n";
    }

    private function sendENQ(): void
    {
        socket_write($this->socket, self::ENQ, strlen(self::ENQ));
        Log::channel('default_client_log')->info(now() . ' -> ENQ sent');
        echo "ENQ sent\n";
    }

    private function sendEOT(): void
    {
        $this->order_record = '';
        $this->order_requested = false;
        $this->receiving = true;
        $this->barcode = '';
        socket_write($this->socket, self::EOT, strlen(self::EOT));
        Log::channel('default_client_log')->info(now() . ' -> EOT sent');
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
        Log::channel('default_client_log')->info(now() . ' -> Header received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('default_client_log')->info('Header string: ' . $inc);
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function handlePatient(string $inc): void
    {
        Log::channel('default_client_log')->info(now() . ' -> Patient received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('default_client_log')->info('Patient string: ' . $inc);
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        Log::channel('default_client_log')->info(now() . ' -> Order received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('default_client_log')->info('Order string: ' . $inc);
        echo "Order data received: $inc\n";

        $this->barcode = explode('|', $inc)[3];
        $this->barcode = preg_replace('/[^0-9]/', '', $this->barcode);
        echo "Barcode from order string in results: $this->barcode\n";

        $this->sendACK();
    }

    private function handleOrderRequest(string $inc): void
    {
        $this->order_requested = true;
        Log::channel('default_client_log')->info(now() . ' -> Order request received: ' . $inc);

        // Clean the incoming message
        $inc = $this->cleanMessage($inc);
        Log::channel('default_client_log')->info('Order request string: ' . $inc);
        echo "Order request received: $inc\n";

        // Extract and clean the barcode
        $this->barcode = $this->extractBarcode($inc);
        echo "Barcode from request string in order request: $this->barcode\n";

        // Retrieve the order record
        $this->order_record = $this->getOrderString();
        $this->order_found = (bool) $this->order_record;

        if ($this->order_found) {
            echo "Order string: $this->order_record\n";
        } else {
            echo "Order not found\n";
        }

        // Send acknowledgment
        $this->sendACK();
    }

    private function extractBarcode(string $inc): string
    {
        $barcode = explode('|', $inc)[2];
        return preg_replace('/[^0-9]/', '', $barcode);
    }


    private function handleComment(string $inc): void
    {
        Log::channel('default_client_log')->info(now() . ' -> Comment received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('default_client_log')->info('Comment string: ' . $inc);
        echo "Comment received: $inc\n";
        $this->sendACK();
    }

    private function handleTerminator(string $inc): void
    {
        Log::channel('default_client_log')->info(now() . ' -> Terminator received: ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('default_client_log')->info('Terminator string: ' . $inc);
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
        $inc = substr($inc, 0, -8);
        Log::channel('default_client_log')->info('Message for checksum calculation: ' . $inc);
        echo "Message for checksum calculation: $inc\n";
        return $inc;
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
        $checksum = array_reduce($hexArray, function($carry, $hex) {
            return $carry + hexdec($hex);
        }, 0);

        $checksum &= 0xFF;
        return strtoupper(dechex($checksum));
    }


    private function getOrderString(): ?string
    {
        $order = Order::where('test_barcode', $this->barcode)->first();
        if (!$order) {
            $order = Order::where('order_barcode', $this->barcode)->first();
        }

        return $order->order_record ?? null;
    }

    private function handleResult(string $inc): void
    {
        Log::channel('default_client_log')->info(now() . 'Result received: ' . $inc);

        // Clean the incoming message
        $inc = $this->cleanMessage($inc);
        Log::channel('default_client_log')->info('Result string: ' . $inc);
        echo "Result received: $inc\n";

//    $barcode = explode('|', $inc)[2];
//    $barcode = preg_replace('/[^0-9]/', '', $barcode);
//    echo "Barcode: $barcode\n";

        // Extract LIS code, result, unit, and reference range
        [, , $lis_code, $result, $unit, $ref_range] = explode('|', $inc);

        // Clean LIS code
        $lis_code = ltrim($lis_code, "^");
        echo "LIS code: $lis_code\n";
        echo "Result: $result\n";
        echo "Unit: $unit\n";
        echo "Reference range: $ref_range\n";

        // Save the result
        $result_saved = $this->saveResult($inc);

        // Handle the result saving outcome
        if ($result_saved) {
            Log::channel('default_client_log')->info(now() . ' -> Result saved');
            $this->sendACK();
            echo "Result saved\n";
        } else {
            Log::channel('default_client_log')->error(now() . ' -> Result save error');
            $this->sendNAK();
            echo "Result not saved\n";
        }
    }


    private function handleOrderNotFound(): void
    {
        echo "Order requested but not found\n";
        Log::channel('default_client_log')->info(now() . 'Order requested but not found for barcode ' . $this->barcode);
        $this->sendENQ();
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            Log::channel('default_client_log')->info(now() . 'ACK received');
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
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            Log::channel('default_client_log')->info(now() . 'ACK received');
            echo "ACK received\n";
            $this->sendMessages();
        }
    }

    private function prepareMessageString(string $message): string
    {
        // Append control characters and calculate checksum
        $message .= self::CR . self::ETX;
        $checksum = $this->getChecksum($message);
        $checksum = str_pad($checksum, 2, '0', STR_PAD_LEFT);

        echo "Checksum: $checksum\n";

        // Append checksum and final control characters
        $message .= $checksum . self::CR . self::LF;
        $message = self::STX . $message;
        echo "Message to send: $message\n";

        // Convert message to hexadecimal representation
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
            $this->sendMessage($this->header);
            $this->header = '';

            if (!empty($this->order_record)) {
                $this->sendMessage($this->patient);
                $this->patient = '';

                $this->sendMessage($this->order_record);
                $this->order_record = '';

                $this->sendMessage($this->terminator);
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
    private function sendMessage(string $message): void
    {
        socket_write($this->socket, $message, strlen($message));
        $inc = socket_read($this->socket, 1024);

        if ($inc !== self::ACK) {
            throw new Exception('ACK not received');
        }
    }


    private function cleanMessage(string $inc): string
    {
        // Remove STX from the start
        $inc = substr($inc, 2);

        // Remove LF, CR, checksum, ETX, CR from the end
        $inc = substr($inc, 0, -12);

        // Convert the cleaned message from hex to binary
        return hex2bin($inc);
    }


    private function reconnect(): void
    {
        Log::channel('default_client_log')->error(now() . 'Reconnecting...');
        echo "Reconnecting...\n";
        socket_close($this->socket);
        $connected = $this->connect();
        if ($connected) {
            $this->process();
        } else {
            sleep(10);
            $this->reconnect();
        }
    }

    private function saveResult(string $inc): bool
    {
        // Extract data from the incoming string
        list(, , $analyte_code, $result, $unit, $ref_range) = explode('|', $inc);
        $analyte_code = ltrim($analyte_code, "^");

        // Find the test by analyte code
        $test = Test::where('name', $analyte_code)->first();
        $lis_code = $test ? $test->test_id : null;

        // Find the order by barcode
        $order = Order::where('test_barcode', $this->barcode)->first() ?? Order::where('order_barcode', $this->barcode)->first();

        // Create a new result
        $result = new Result([
            'order_id' => $order->id ?? null,
            'barcode' => $this->barcode,
            'analyte_code' => $analyte_code,
            'lis_code' => $lis_code,
            'result' => $result,
            'unit' => $unit,
            'reference_range' => $ref_range,
        ]);

        return $result->save();
    }

    private function handleTimeout(): void
    {
        echo "Timeout occurred\n";
    }
}

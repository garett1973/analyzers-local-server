<?php

namespace App\Libraries\Analyzers\Default;

use App\Enums\HexCodes;
use App\Models\Order;
use App\Models\Result;
use App\Models\Test;
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
//        $ip = '85.206.48.46';
////        $ip = '192.168.1.111';
//        $port = 9999;

        $ip = '127.0.0.1';
        $port = 12000;

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            echo "Socket connection failed: " . socket_strerror(socket_last_error($this->socket)) . "\n";
        } else {
            Log::channel('analyzer_communication')->debug('Connection to analyzer established at ' . now());
            echo "Connection established\n";
        }

        return $this->connection;
    }

    public function process(): void
    {
            while (true) {
                if ($this->connection) {
                    socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 15, 'usec' => 0]);
                echo "Receiving: $this->receiving\n";
                while ($this->receiving) {
                    $inc = socket_read($this->socket, 1024);

                    if ($inc === false) {
                        // Handle timeout
                        echo "Timeout occurred, no response for 15 seconds\n";
                        $this->handleTimeout();
                        break;
                    }

                    if ($inc) {
                        switch ($inc) {
                            case self::ENQ:
                                $this->handleEnq();
                                break;
                            case self::EOT:
                                $this->receiving = $this->handleEot();
                                break;
                            default:
//                                $inc = bin2hex($inc);
                                $header = $this->getDataMessageFirstSegment($inc);
                                if (!$header) {
                                    $this->sendNAK();
                                    break;
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
                    }
                }
                echo "Receiving stopped, order was requested\n";
                if (!$this->order_found) {
                    $this->handleOrderNotFound();
                } else {
                    $this->sendOrderRecord();
                }
            } else {
                $this->reconnect();
            }
        }
    }

    private function handleEnq(): void
    {
        echo "ENQ received\n";
        Log::channel('analyzer_communication')->info('ENQ received at ' . now());
        $this->sendACK();
    }

    private function handleEot(): bool
    {
        echo "EOT received\n";
        Log::channel('analyzer_communication')->info('EOT received at ' . now());
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
        Log::channel('analyzer_communication')->info('ACK sent at ' . now());
        echo "ACK sent\n";
    }

    private function sendNAK(): void
    {
        socket_write($this->socket, self::NAK, strlen(self::NAK));
        Log::channel('analyzer_communication')->info('NAK sent at ' . now());
        echo "NAK sent\n";
    }

    private function sendENQ(): void
    {
        socket_write($this->socket, self::ENQ, strlen(self::ENQ));
        Log::channel('analyzer_communication')->info('ENQ sent at ' . now());
        echo "ENQ sent\n";
    }

    private function sendEOT(): void
    {
        $this->order_record = '';
        $this->order_requested = false;
        $this->receiving = true;
        $this->barcode = '';
        socket_write($this->socket, self::EOT, strlen(self::EOT));
        Log::channel('analyzer_communication')->info('EOT sent at ' . now());
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
        Log::channel('analyzer_communication')->info('Header received at ' . now() . ': ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('analyzer_communication')->info('Header string: ' . $inc);
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function handlePatient(string $inc): void
    {
        Log::channel('analyzer_communication')->info('Patient received at ' . now() . ': ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('analyzer_communication')->info('Patient string: ' . $inc);
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        Log::channel('analyzer_communication')->info('Order received at ' . now() . ': ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('analyzer_communication')->info('Order string: ' . $inc);
        echo "Order data received: $inc\n";

        $this->barcode = explode('|', $inc)[3];
        $this->barcode = preg_replace('/[^0-9]/', '', $this->barcode);
        echo "Barcode from order string in results: $this->barcode\n";

        $this->sendACK();
    }

    private function handleOrderRequest(string $inc): void
    {
        $this->order_requested = true;
        Log::channel('analyzer_communication')->info('Order request received at ' . now() . ': ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('analyzer_communication')->info('Order request string: ' . $inc);
        echo "Order request received: $inc\n";

        $this->barcode = explode('|', $inc)[2];
        $this->barcode = preg_replace('/[^0-9]/', '', $this->barcode);
        echo "Barcode from request string in order request: $this->barcode\n";

        $this->order_record = $this->getOrderString();
        if ($this->order_record) {
            $this->order_found = true;
            echo "Order string: $this->order_record\n";
        } else {
            echo "Order not found\n";
        }
        $this->sendACK();
    }

    private function handleComment(string $inc): void
    {
        Log::channel('analyzer_communication')->info('Comment received at ' . now() . ': ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('analyzer_communication')->info('Comment string: ' . $inc);
        echo "Comment received: $inc\n";
        $this->sendACK();
    }

    private function handleTerminator(string $inc): void
    {
        Log::channel('analyzer_communication')->info('Terminator received at ' . now() . ': ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('analyzer_communication')->info('Terminator string: ' . $inc);
        echo "Terminator received: $inc\n";
        $this->sendACK();
    }

    private function processMessage(false|string $inc): false|string
    {
        $checksum = $this->getChecksumFromString($inc);
        $prepared_inc = $this->prepareMessageForChecksumCalculation($inc);
        $checksum_ok = $this->checkChecksum($prepared_inc, $checksum);

        if (!$checksum_ok) {
            return false;
        }

        return $this->cleanMessage($inc);
    }

    private function getChecksumFromString(string $inc): string
    {
        // remove LF, CR from message end
        $inc = substr($inc, 0, -4);
        // get checksum 1, checksum 2
        $checksum =  substr($inc, -4);
        // convert checksum from hex to string
        return strtoupper(hex2bin($checksum));
    }

    private function prepareMessageForChecksumCalculation(string $inc): string
    {
        // remove STX from message start
        $inc = substr($inc, 2);
        // remove LF, CR from message end
        $inc = substr($inc, 0, -4);
        // remove checksum 2, checksum 1
        return substr($inc, 0, -4);
    }

    private function checkChecksum(string $inc, string $checksum): bool
    {
        if ($checksum[0] == '0') {
            $checksum = substr($checksum, 1);
        }
        $calculatedChecksum = $this->calculateChecksum($inc);
        if ($calculatedChecksum != $checksum) {
            return false;
        }
        echo "Checksum OK\n";
        return true;
    }

    private function calculateChecksum(string $inc): string
    {
        $hex_array = str_split($inc, 2);
        $checksum = 0;
        foreach ($hex_array as $hex) {
            $checksum += hexdec($hex);
        }
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
        Log::channel('analyzer_communication')->info('Result received at ' . now() . ': ' . $inc);
        $inc = $this->cleanMessage($inc);
        Log::channel('analyzer_communication')->info('Result string: ' . $inc);
        echo "Result received: $inc\n";

//        $barcode = explode('|', $inc)[2];
//        $barcode = preg_replace('/[^0-9]/', '', $barcode);
//        echo "Barcode: $barcode\n";

        $lis_code = explode('|', $inc)[2];
        $lis_code = ltrim($lis_code, "^");
        echo "LIS code: $lis_code\n";

        $result = explode('|', $inc)[3];
        echo "Result: $result\n";

        $unit = explode('|', $inc)[4];
        echo "Unit: $unit\n";

        $ref_range = explode('|', $inc)[5];
        echo "Reference range: $ref_range\n";

        $result_saved = $this->saveResult($inc);

        if ($result_saved) {
            $this->sendACK();
            Log::channel('analyzer_communication')->info('Result saved at ' . now());
            Log::channel('analyzer_communication')->info('ACK sent at ' . now());
            echo "Result saved\n";
        } else {
            $this->sendNAK();
            Log::channel('analyzer_communication')->error('Result save error ' . now());
            Log::channel('analyzer_communication')->error('NAK sent at ' . now());
            echo "Result not saved\n";
        }
    }

    private function handleOrderNotFound(): void
    {
        echo "Order requested but not found\n";
        Log::channel('analyzer_communication')->info('Order requested but not found at ' . now());
        $this->sendENQ();
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            $this->sendEOT();
        }
    }

    private function sendOrderRecord(): void
    {
        $this->header = $this->prepareMessageString($this->getShortHeader());
        $this->patient = $this->prepareMessageString($this->getPatient());
        $this->order_record = $this->prepareMessageString($this->order_record);
        $this->terminator = $this->prepareMessageString($this->getTerminator());

        socket_write($this->socket, self::ENQ, strlen(self::ENQ));
        Log::channel('analyzer_communication')->info('ENQ sent at ' . now());
        echo "ENQ sent\n";
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            Log::channel('analyzer_communication')->info('ACK received at ' . now());
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
        echo "Sending order information...\n";
        try {
            socket_write($this->socket, $this->header, strlen($this->header));
            Log::channel('analyzer_communication')->info('Header sent at ' . now());
            Log::channel('analyzer_communication')->info('Header hex string: ' . $this->header);
            echo "Header sent\n";
            $inc = socket_read($this->socket, 1024);
            if ($inc == self::ACK) {
                $this->header = '';
                Log::channel('analyzer_communication')->info('ACK received at ' . now());
                echo "ACK received\n";
                if ($this->order_record != '') {
                    try {
                        socket_write($this->socket, $this->patient, strlen($this->patient));
                        Log::channel('analyzer_communication')->info('Patient sent at ' . now());
                        Log::channel('analyzer_communication')->info('Patient hex string: ' . $this->patient);
                        echo "Patient sent\n";
                        $inc = socket_read($this->socket, 1024);
                        if ($inc == self::ACK) {
                            $this->patient = '';
                            Log::channel('analyzer_communication')->info('ACK received at ' . now());
                            echo "ACK received\n";
                            try {
                                socket_write($this->socket, $this->order_record, strlen($this->order_record));
                                Log::channel('analyzer_communication')->info('Order record sent at ' . now());
                                Log::channel('analyzer_communication')->info('Order record hex string: ' . $this->order_record);
                                echo "Order record sent\n";
                                $inc = socket_read($this->socket, 1024);
                                if ($inc == self::ACK) {
                                    $this->order_record = '';
                                    Log::channel('analyzer_communication')->info('ACK received at ' . now());
                                    echo "ACK received\n";
                                    try {
                                        socket_write($this->socket, $this->terminator, strlen($this->terminator));
                                        Log::channel('analyzer_communication')->info('Terminator sent at ' . now());
                                        Log::channel('analyzer_communication')->info('Terminator hex string: ' . $this->terminator);
                                        echo "Terminator sent\n";
                                        $inc = socket_read($this->socket, 1024);
                                        if ($inc == self::ACK) {
                                            $this->terminator = '';
                                            Log::channel('analyzer_communication')->info('ACK received at ' . now());
                                            echo "ACK received\n";
                                            $this->sendEOT();
                                        }
                                    } catch (\Exception $e) {
                                        Log::channel('analyzer_communication')->error('Terminator error: ' . $e->getMessage());
                                        echo "Terminator error: " . $e->getMessage() . "\n";
                                        $this->reconnect();
                                    }
                                }
                            } catch (\Exception $e) {
                                Log::channel('analyzer_communication')->error('Order record error: ' . $e->getMessage());
                                echo "Order record error: " . $e->getMessage() . "\n";
                                $this->reconnect();
                            }
                        }
                    } catch (\Exception $e) {
                        Log::channel('analyzer_communication')->error('Patient error: ' . $e->getMessage());
                        echo "Patient error: " . $e->getMessage() . "\n";
                        $this->reconnect();
                    }
                } else {
                    $this->sendEOT();
                }
            }
        } catch (\Exception $e) {
            Log::channel('analyzer_communication')->error('Header error: ' . $e->getMessage());
            echo "Header error: " . $e->getMessage() . "\n";
            $this->reconnect();
        }
    }

    private function cleanMessage(string $inc): string
    {
        // remove STX from message start
        $inc = substr($inc, 2);
        // remove LF, CR from message end
        $inc = substr($inc, 0, -4);
        // remove checksum 2, checksum 1, ETX, CR
        $inc = substr($inc, 0, -8);
        return hex2bin($inc);
    }

    private function reconnect(): void
    {
        Log::channel('analyzer_communication')->error('Reconnecting at ' . now());
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
        $analyte_code = explode('|', $inc)[2];
        $analyte_code = ltrim($analyte_code, "^");

        $test = Test::where('name', $analyte_code)->first();

        if ($test) {
            $lis_code = $test->test_id;
        } else {
            $lis_code = null;
        }

        $result = explode('|', $inc)[3];

        $unit = explode('|', $inc)[4];

        $ref_range = explode('|', $inc)[5];

        $order = Order::where('test_barcode', $this->barcode)->first();

        if (!$order) {
            $order = Order::where('order_barcode', $this->barcode)->first();
        }

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

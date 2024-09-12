<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Models\Order;

class UniCell_Dxl_800
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETB = HexCodes::ETB->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;

    private static ?UniCell_Dxl_800 $instance = null;
    private $socket;
    private $connection;
    private int $counter = 0;
    private string $header = '';
    private string $patient = '';
    private string $terminator = '';
    private string $order_record = '';
    private bool $order_request_received = false;
    private bool $message_received = false;

    public function __construct()
    {

    }

    public static function getInstance(): UniCell_Dxl_800
    {
        if (self::$instance == null) {
            self::$instance = new UniCell_Dxl_800();
        }

        return self::$instance;
    }

    public function connect(): bool
    {
        $ip = '127.0.0.1';
        $port = 12000;

        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            echo "Socket connection failed: " . socket_strerror(socket_last_error($this->socket)) . "\n";
        } else {
            $this->counter = 0;
            echo "Socket connected\n";
        }

        return $this->connection;
    }

    public function process(): void
    {
        if ($this->connection) {
            echo "Connection established\n";
            socket_set_nonblock($this->socket); // Set the socket to non-blocking mode

            while (true) {

                if ($this->order_request_received && $this->message_received) {
                    $this->sendOrderRecord();
                }

                $read = [$this->socket];
                $write = null;
                $except = null;
                $timeout = 30; // Timeout in seconds

                // Use socket_select to wait for data with a timeout
                $numChangedSockets = socket_select($read, $write, $except, $timeout);

                if ($numChangedSockets === false) {
                    echo "Socket error: " . socket_strerror(socket_last_error()) . "\n";
                    break;
                }

                if ($numChangedSockets > 0) {
                    try {
                        $inc = socket_read($this->socket, 1024);
                    } catch (\Exception $e) {
                        echo "Socket read error: " . $e->getMessage() . "\n";
                        $this->reconnect();
                    }
                    if ($inc) {
                        switch ($inc) {
                            case self::ENQ:
                                $this->handleEnq();
                                break;
                            case self::EOT:
                                $this->handleEot();
                                break;
                            default:
                                $header = $this->getDataMessageHeader($inc);
                                if ($header) {
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
                                        case 'C':
                                            $this->handleComment($inc);
                                            break;
                                        case 'Q':
                                            $this->handleInformationRequest($inc);
                                            break;
                                        case 'L':
                                            $this->handleTerminator($inc);
                                            break;
                                    }
                                } else {
                                    try {
                                        socket_write($this->socket, self::NAK, strlen(self::NAK));
                                    } catch (\Exception $e) {
                                        echo "NAK error: " . $e->getMessage() . "\n";
                                        $this->reconnect();
                                    }
                                }
                        }
                    }
                } else {
                    // Timeout occurred
                    echo "No new messages or EOT signal received within timeout period\n";
                    $this->connection = false;
                    $this->reconnect();
                }
            }
        } else {
            echo "Connection failed\n";
            sleep(5);
            if ($this->counter < 10) {
                $this->connect();
                $this->counter++;
            }
        }
    }


    private function handleEnq(): void
    {
        echo "ENQ received\n";
        $this->sendACK();
    }

    private function handleEot(): void
    {
        echo "EOT received\n";
        if ($this->order_request_received) {
            $this->sendOrderRecord();
        }
        $this->message_received = true;
    }

    private function getDataMessageHeader($inc): false|string
    {
        echo "Data message received: $inc\n";
        $inc = $this->processMessage($inc);
        echo "Processed message: $inc\n";
        if (!$inc) {
            return false;
        }

        // remove frame number to get the header character
        $inc = substr($inc, 1);
        return explode('|', $inc)[0];
    }

    private function handleHeader(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Header received: $inc\n";
        $this->sendACK();
    }

    private function sendACK(): void
    {
        try {
            socket_write($this->socket, self::ACK, strlen(self::ACK));
            echo "ACK sent\n";
        } catch (\Exception $e) {
            echo "ACK error: " . $e->getMessage() . "\n";
            $this->reconnect();
        }
    }

    private function handlePatient(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Patient data received: $inc\n";
        $this->sendACK();
    }

    private function handleOrder(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Order data received: $inc\n";
        $this->sendACK();
    }

    private function handleResult(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Result received: $inc\n";
        $this->sendACK();
    }

    private function handleComment(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Comment received: $inc\n";
        $this->sendACK();
    }

    private function handleInformationRequest(string $inc): void
    {
        $this->order_request_received = true;

        $inc = $this->cleanMessage($inc);
        echo "Information request received: $inc\n";

        $barcode = explode('|', $inc)[2];
        $barcode = str_replace('^', '', $barcode);
        echo "Barcode: $barcode\n";

        $order = Order::where('test_barcode', $barcode)->first();
        if (!$order) {
            $order = Order::where('order_barcode', $barcode)->first();
        }

        if ($order) {
            $this->order_record = $order->order_record;
            echo "Order record found: $this->order_record\n";
        }

        $this->sendACK();
    }

    private function handleTerminator(string $inc): void
    {
        $inc = $this->cleanMessage($inc);
        echo "Terminator received: $inc\n";
        $this->sendACK();
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
//        echo "Calculating checksum for: $inc\n";
        $hex_array = str_split($inc, 2);
        $checksum = 0;
        foreach ($hex_array as $hex) {
            $checksum += hexdec($hex);
        }
        $checksum = $checksum & 0xFF;
        return strtoupper(dechex($checksum));
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

    private function sendOrderRecord(): void
    {
        $this->header = $this->prepareMessageString($this->getShortHeader());
        $this->patient = $this->prepareMessageString($this->getPatient());
        $this->order_record = $this->prepareMessageString($this->order_record);
        $this->terminator = $this->prepareMessageString($this->getTerminator());

        socket_write($this->socket, self::ENQ, strlen(self::ENQ));
        echo "ENQ sent\n";
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
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
        return strtoupper(bin2hex($message));
    }

    private function getChecksum(string $message): string
    {
        $checksum = 0;
        for ($i = 0, $iMax = strlen($message); $i < $iMax; $i++) {
            $checksum += ord($message[$i]);
        }
        $checksum %= 256;
        $checksum = $checksum & 0xFF;
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
            echo "Header sent\n";
            $inc = socket_read($this->socket, 1024);
            if ($inc == self::ACK) {
                echo "ACK received\n";
                if ($this->order_record != '') {
                    try {
                        socket_write($this->socket, $this->patient, strlen($this->patient));
                        echo "Patient sent\n";
                        $inc = socket_read($this->socket, 1024);
                        if ($inc == self::ACK) {
                            echo "ACK received\n";
                            try {
                                socket_write($this->socket, $this->order_record, strlen($this->order_record));
                                echo "Order record sent\n";
                                $inc = socket_read($this->socket, 1024);
                                if ($inc == self::ACK) {
                                    echo "ACK received\n";
                                    try {
                                        socket_write($this->socket, $this->terminator, strlen($this->terminator));
                                        echo "Terminator sent\n";
                                        $inc = socket_read($this->socket, 1024);
                                        if ($inc == self::ACK) {
                                            echo "ACK received\n";
                                            $this->order_request_received = false;
                                            $this->order_record = '';
                                            socket_write($this->socket, self::EOT, strlen(self::EOT));
                                        }
                                    } catch (\Exception $e) {
                                        echo "Terminator error: " . $e->getMessage() . "\n";
                                        $this->reconnect();
                                    }
                                }
                            } catch (\Exception $e) {
                                echo "Order record error: " . $e->getMessage() . "\n";
                                $this->reconnect();
                            }
                        }
                    } catch (\Exception $e) {
                        echo "Patient error: " . $e->getMessage() . "\n";
                        $this->reconnect();
                    }
                } else {
                    socket_write($this->socket, self::EOT, strlen(self::EOT));
                }
            }
        } catch (\Exception $e) {
            echo "Header error: " . $e->getMessage() . "\n";
            $this->reconnect();
        }
    }
}

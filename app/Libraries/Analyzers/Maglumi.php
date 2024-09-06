<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Models\Analyzer;
use App\Models\AnalyzerType;
use App\Models\Order;

class Maglumi
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;

    const BAUD_RATE = 9600;
    const DATA_BITS = 8;
    const STOP_BITS = 1;
    const PARITY = 0;

    private static ?Maglumi $instance = null;
    private $socket;
    private $connection;
    private bool $receiving = true;
    private bool $order_requested = false;
    private bool $order_found = false;
    private string $order_string = '';

    public function __construct()
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            echo "Socket creation failed: " . socket_strerror(socket_last_error()) . "\n";
        }
    }

    public static function getInstance(): Maglumi
    {
        if (self::$instance == null) {
            self::$instance = new Maglumi();
        }

        return self::$instance;
    }

    public function connect(): bool
    {
        $analyzer_type_id = AnalyzerType::where('name', 'Maglumi')->first()->id;
        $ip = Analyzer::where('type_id', $analyzer_type_id)
            ->where('lab_id', config('laboratories.lab_id'))
            ->where('is_active', true)
            ->first()
            ->local_ip;

        $port = 12000;
//        $ip = '10.150.9.64';
//        $port = 53266;


//        $ip = '0.tcp.eu.ngrok.io';
//        $port = '10820';

        $context  = stream_context_create([
            'serial' => [
                'baud_rate' => self::BAUD_RATE,
                'data_bits' => self::DATA_BITS,
                'stop_bits' => self::STOP_BITS,
                'parity' => self::PARITY,
            ],
        ]);

//        $this->connection = stream_socket_client("tcp://$ip:$port", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            echo "Socket connection failed: " . socket_strerror(socket_last_error($this->socket)) . "\n";
        }

        return $this->connection;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function process($order = null): void
    {
        if ($this->connection) {
            echo "Connection established\n";
            while (true) {
                echo "Receiving: $this->receiving\n";
                while ($this->receiving) {
                    $inc = socket_read($this->socket, 1024);
                    if ($inc) {
                        switch ($inc) {
                            case self::ENQ:
                                $this->handleEnq($this->socket);
                                break;
                            case self::STX:
                                $this->handleStx();
                                break;
                            case self::ETX:
                                $this->handleEtx($this->socket);
                                break;
                            case self::EOT:
                                $this->receiving = $this->handleEot();
                                break;
                            default:
                                $header = $this->handleDataMessage($inc);
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
                if ($this->order_requested && !$this->order_found) {
                    $this->handleOrderNotFound();
                } else {
                    sleep(1);
                    $this->sendENQ($this->socket);
                    $inc = socket_read($this->socket, 1024);
                    if ($inc == self::ACK) {
                        sleep(1);
                        $this->sendSTX($this->socket);
                    } else if ($inc == self::NAK) {
                        echo "NAK received\n";
                        $this->resetSendingOrder();
                        continue;
                    } else if ($inc == self::ENQ) {
                        echo "ENQ received\n";
                        $this->resetSendingOrder();
                        continue;
                    }
                    $this->handleSendOrderInformation();
                }
            }
        }
    }

    private function handleEnq($socket): void
    {
        echo "ENQ received\n";
        sleep(1);
        socket_write($socket, self::ACK, strlen(self::ACK));
        echo "ACK sent\n";
    }

    private function handleStx(): void
    {
        echo "STX received\n";
//        socket_write($socket, self::ACK, strlen(self::ACK));
    }

    private function handleEtx($socket): void
    {
        echo "ETX received\n";
        sleep(1);
        socket_write($socket, self::ACK, strlen(self::ACK));
        echo "ACK sent\n";
    }

    private function handleEot(): bool
    {
        echo "EOT received\n";
//        socket_write($socket, self::ACK, strlen(self::ACK));
        if ($this->order_requested) {
            return false;
        }
        return true;
    }

    private function handleDataMessage($inc): false|string
    {
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        return explode('|', $inc)[0];
    }

    private function getOrderString(string $barcode): ?string
    {
        $order = Order::where('order_barcode', $barcode)->first();
        if (!$order) {
            $order = Order::where('test_barcode', $barcode)->first();
        }

        return $order->order_record ?? null;
    }

    public function shortHeader(): string
    {
        return 'H|\^&';
    }

    public function patientRecord(): string
    {
        return 'P|1';
    }

    public function terminator(): string
    {
        return 'L|1|N';
    }

    public function getResult(): string
    {
        return 'Order processed';
    }

    private function handleHeader(string $inc): void
    {
        $checksum = $this->checkChecksum($inc);
        if (!$checksum) {
            echo "Header checksum failed\n";
            return;
        }
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        echo "Header received: $inc\n";
    }

    private function handlePatient(string $inc): void
    {
        $checksum = $this->checkChecksum($inc);
        if (!$checksum) {
            echo "Patient checksum failed\n";
            return;
        }
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        echo "Patient data received: $inc\n";
    }

    private function handleOrder(string $inc): void
    {
        $checksum = $this->checkChecksum($inc);
        if (!$checksum) {
            echo "Order data checksum failed\n";
            return;
        }
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        echo "Order data received: $inc\n";
    }

    private function handleOrderRequest(string $inc): void
    {
        $checksum = $this->checkChecksum($inc);
        if (!$checksum) {
            echo "Order request checksum failed\n";
            return;
        }
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        echo "Order request received: $inc\n";
        $barcode = explode('|', $inc)[2];
        $barcode = preg_replace('/[^0-9]/', '', $barcode);
        echo "Barcode: $barcode\n";
        $this->order_string = $this->getOrderString($barcode);
        if ($this->order_string) {
            $this->order_requested = true;
            $this->order_found = true;
            echo "Order string: $this->order_string\n";
        } else {
            echo "Order not found\n";
        }
    }

    private function handleResult(string $inc): void
    {
        $checksum = $this->checkChecksum($inc);
        if (!$checksum) {
            echo "Result checksum failed\n";
            return;
        }
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        echo "Result received: $inc\n";
        $barcode = explode('|', $inc)[2];
        $barcode = preg_replace('/[^0-9]/', '', $barcode);
        echo "Barcode: $barcode\n";
        $lis_code = explode('|', $inc)[3];
        $lis_code = ltrim($lis_code, "^");
        echo "LIS code: $lis_code\n";
        $result = explode('|', $inc)[4];
        echo "Result: $result\n";
        $unit = explode('|', $inc)[5];
        echo "Unit: $unit\n";
        $ref_range = explode('|', $inc)[6];
        echo "Reference range: $ref_range\n";
    }

    private function handleComment(string $inc): void
    {
        $checksum = $this->checkChecksum($inc);
        if (!$checksum) {
            echo "Comment checksum failed\n";
            return;
        }
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        echo "Comment received: $inc\n";
    }

    private function handleTerminator(string $inc): void
    {
        $checksum = $this->checkChecksum($inc);
        if (!$checksum) {
            echo "Terminator checksum failed\n";
            return;
        }
        $inc = substr($inc, 0, -2);
        $inc = hex2bin($inc);
        echo "Terminator received: $inc\n";
    }

    private function checkChecksum(false|string $inc): bool
    {
        $checksum = substr($inc, -2);
        $inc = substr($inc, 0, -2);
        $checksumCalc = 0;
        for ($i = 0; $i < strlen($inc); $i++) {
            $checksumCalc += ord($inc[$i]);
        }
        $checksumCalc = $checksumCalc & 0xFF;
        $checksumCalc = dechex($checksumCalc);
        return $checksum == $checksumCalc;
    }

    private function sendENQ(false|\Socket $socket): void
    {
        socket_write($socket, self::ENQ, strlen(self::ENQ));
        echo "ENQ sent\n";
    }

    private function sendEOT(false|\Socket $socket): void
    {
        socket_write($socket, self::EOT, strlen(self::EOT));
        echo "EOT sent\n";
    }

    private function sendETX(false|\Socket $socket): void
    {
        socket_write($socket, self::ETX, strlen(self::ETX));
        echo "ETX sent\n";
    }

    private function sendShortHeader(false|\Socket $socket): void
    {
        $header = $this->shortHeader();
        $header = bin2hex($header);
        $checksum = $this->getChecksum($header);
        $header .= $checksum;
        socket_write($socket, $header, strlen($header));
        echo "Short header sent\n";
    }

    private function sendPatientRecord(false|\Socket $socket): void
    {
        $patient = $this->patientRecord();
        $patient = bin2hex($patient);
        $checksum = $this->getChecksum($patient);
        $patient .= $checksum;
        socket_write($socket, $patient, strlen($patient));
        echo "Patient record sent\n";
    }

    private function sendOrderRecord(false|\Socket $socket): void
    {
        $order = $this->order_string;
        $order = bin2hex($order);
        $checksum = $this->getChecksum($order);
        $order .= $checksum;
        socket_write($socket, $order, strlen($order));
        echo "Order record sent\n";
    }

    private function sendTerminator(false|\Socket $socket): void
    {
        $terminator = $this->terminator();
        $terminator = bin2hex($terminator);
        $checksum = $this->getChecksum($terminator);
        $terminator .= $checksum;
        socket_write($socket, $terminator, strlen($terminator));
        echo "Terminator sent\n";
    }

    private function getChecksum(string $msg): string
    {
        $checksum = 0;
        for ($i = 0; $i < strlen($msg); $i++) {
            $checksum += ord($msg[$i]);
        }
        $checksum = $checksum & 0xFF;
        return dechex($checksum);
    }

    private function sendSTX(false|\Socket $socket): void
    {
        socket_write($socket, self::STX, strlen(self::STX));
        echo "STX sent\n";
    }

    private function handleOrderNotFound(): void
    {
        echo "Order requested but not found\n";
        sleep(1);
        $this->sendENQ($this->socket);
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            sleep(1);
            $this->sendEOT($this->socket);
        }
    }

    private function resetSendingOrder(): void
    {
        $this->receiving = true;
        $this->order_requested = false;
        $this->order_found = false;
    }

    private function handleSendOrderInformation(): void
    {
        sleep(1);
        $this->sendShortHeader($this->socket);
        sleep(1);
        $this->sendPatientRecord($this->socket);
        sleep(1);
        $this->sendOrderRecord($this->socket);
        sleep(1);
        $this->sendTerminator($this->socket);
        sleep(1);
        $this->sendETX($this->socket);
        sleep(1);
        $this->sendEOT($this->socket);
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            echo "ACK received\n";
            echo "Order processed, switching to receiving\n";
            $this->resetSendingOrder();
        }
    }
}



<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Models\Order;

class DefaultAnalyzer
{
    const ACK = HexCodes::ACK->value;
    const NAK = HexCodes::NAK->value;
    const ENQ = HexCodes::ENQ->value;
    const STX = HexCodes::STX->value;
    const ETX = HexCodes::ETX->value;
    const EOT = HexCodes::EOT->value;
    const CR = HexCodes::CR->value;
    const LF = HexCodes::LF->value;
    const TERMINATOR = self::CR . self::LF;

    private static ?DefaultAnalyzer $instance = null;
    private $socket;
    private $connection;
    private Order $order;
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

    public static function getInstance(): DefaultAnalyzer
    {
        if (self::$instance == null) {
            self::$instance = new DefaultAnalyzer();
        }

        return self::$instance;
    }

    public function connect(): bool
    {
        $ip = '127.0.0.1';
        $port = 12000;

        $this->connection = @socket_connect($this->socket, $ip, $port);
        if ($this->connection === false) {
            echo "Socket connection failed: " . socket_strerror(socket_last_error($this->socket)) . "\n";
        } else {
            echo "Socket connected\n";
        }

        return $this->connection;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function process(): void
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
                                $this->handleEnq();
                                break;
                            case self::EOT:
                                $this->receiving = $this->handleEot();
                                break;
                            default:
                                $header = $this->getDataMessageHeader($inc);
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
                    $this->sendENQ();
                    $inc = socket_read($this->socket, 1024);
                    if ($inc == self::ACK) {
                        sleep(1);
                        $this->handleSendOrderInformation();
                    } else {
                        if ($inc == self::NAK) {
                            echo "NAK received\n";
                            $this->resetSendingOrder();
                        } else {
                            if ($inc == self::ENQ) {
                                echo "ENQ received\n";
                                $this->resetSendingOrder();
                            }
                        }
                    }
                }
            }
        }
    }

    private function handleEnq(): void
    {
        echo "ENQ received\n";
        sleep(1);
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        echo "ACK sent\n";
    }

    private function handleEot(): bool
    {
        echo "EOT received\n";
        if ($this->order_requested) {
            return false;
        }
        return true;
    }

    private function getDataMessageHeader($inc): false|string
    {
        // remove STX from message start
        $inc = substr($inc, 1);
        // remove LF, CR, checksum and ETX from message end
        $inc = substr($inc, 0, -5);
        $inc = hex2bin($inc);
        return explode('|', $inc)[0];
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
        $this->sendACK();
    }

    private function checkChecksum(false|string $inc): bool
    {
        //
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

    private function sendACK(): void
    {
        socket_write($this->socket, self::ACK, strlen(self::ACK));
        echo "ACK sent\n";
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
        $this->sendACK();
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
        $this->sendACK();
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
        $this->sendACK();
    }

    private function getOrderString(string $barcode): ?string
    {
        $order = Order::where('order_barcode', $barcode)->first();
        if (!$order) {
            $order = Order::where('test_barcode', $barcode)->first();
        }

        return $order->order_record ?? null;
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
        $this->sendACK();
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
        $this->sendACK();
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
        $this->sendACK();
    }

    private function handleOrderNotFound(): void
    {
        echo "Order requested but not found\n";
        sleep(1);
        $this->sendENQ();
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            sleep(1);
            $this->sendEOT();
        }
    }

    private function sendENQ(): void
    {
        socket_write($this->socket, self::ENQ, strlen(self::ENQ));
        echo "ENQ sent\n";
    }

    private function sendEOT(): void
    {
        socket_write($this->socket, self::EOT, strlen(self::EOT));
        echo "EOT sent\n";
    }

    private function handleSendOrderInformation(): void
    {
        sleep(1);
        $this->sendShortHeader();
        $inc = socket_read($this->socket, 1024);
        if ($inc == self::ACK) {
            echo "ACK received\n";
            sleep(1);
            $this->sendPatientRecord();
            $inc = socket_read($this->socket, 1024);
            if ($inc == self::ACK) {
                sleep(1);
                $this->sendOrderRecord();
                $inc = socket_read($this->socket, 1024);
                if ($inc == self::ACK) {
                    sleep(1);
                    $this->sendTerminator();
                    $inc = socket_read($this->socket, 1024);
                    if ($inc == self::ACK) {
                        sleep(1);
                        $this->sendEOT();
                        echo "Order processed, switching to receiving\n";
                        $this->resetSendingOrder();
                    } else {
                        $this->handleSendOrderInformation();
                    }
                } else {
                    $this->handleSendOrderInformation();
                }
            } else {
                $this->handleSendOrderInformation();
            }
        } else {
            $this->handleSendOrderInformation();
        }
    }

    private function sendShortHeader(): void
    {
        $header = $this->shortHeader();
        $header = bin2hex($header);
        $header = $header . self::ETX;
        $checksum = $this->getChecksum($header);
        $header .= $checksum . self::TERMINATOR;
        $header = self::STX . $header;
        socket_write($this->socket, $header, strlen($header));
        echo "Short header sent\n";
    }

    public function shortHeader(): string
    {
        return 'H|\^&';
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

    private function sendPatientRecord(): void
    {
        $patient = $this->patientRecord();
        $patient = bin2hex($patient);
        $patient = $patient . self::ETX;
        $checksum = $this->getChecksum($patient);
        $patient .= $checksum . self::TERMINATOR;
        $patient = self::STX . $patient;
        socket_write($this->socket, $patient, strlen($patient));
        echo "Patient record sent\n";
    }

    public function patientRecord(): string
    {
        return 'P|1';
    }

    private function sendOrderRecord(): void
    {
        $order = $this->order_string;
        $order = bin2hex($order);
        $order = $order . self::ETX;
        $checksum = $this->getChecksum($order);
        $order .= $checksum . self::TERMINATOR;
        $order = self::STX . $order;
        socket_write($this->socket, $order, strlen($order));
        echo "Order record sent\n";
    }

    private function sendTerminator(): void
    {
        $terminator = $this->terminator();
        $terminator = bin2hex($terminator);
        $terminator = $terminator . self::ETX;
        $checksum = $this->getChecksum($terminator);
        $terminator .= $checksum . self::TERMINATOR;
        $terminator = self::STX . $terminator;
        socket_write($this->socket, $terminator, strlen($terminator));
        echo "Terminator sent\n";
    }

    public function terminator(): string
    {
        return 'L|1|N';
    }

    private function resetSendingOrder(): void
    {
        $this->receiving = true;
        $this->order_requested = false;
        $this->order_found = false;
    }

    private function sendETX(): void
    {
        socket_write($this->socket, self::ETX, strlen(self::ETX));
        echo "ETX sent\n";
    }

    private function sendSTX(): void
    {
        socket_write($this->socket, self::STX, strlen(self::STX));
        echo "STX sent\n";
    }
}

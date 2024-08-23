<?php

namespace App\Libraries\Analyzers;

use App\Enums\HexCodes;
use App\Models\Analyzer;
use App\Models\Order;
use App\Services\SocketManager;

class Maglumi
{
    protected SocketManager $socketManager;

    public function __construct(SocketManager $socketManager)
    {
        $this->socketManager = $socketManager;
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

    public function processOrder(mixed $order): false|string|null
    {
//        $ip = Analyzer::where('analyzer_id', $order['analyzer_id'])
//            ->where('lab_id', $order['lab_id'])
//            ->first()
//            ->local_ip;
//
//        $port = 12000;
//
        $analyzer_name = Analyzer::where('analyzer_id', $order['analyzer_id'])
            ->where('lab_id', $order['lab_id'])
            ->first()
            ->name;


        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
//        $connection = socket_connect($socket, $ip, $port);

        $connections = $this->socketManager->getAllConnections();
        $connection = $connections[$analyzer_name];


//        $status = socket_get_status($socket);
//        $connection = $status['eof'] == 0 && $status['timed_out'] == 0;

        $ack = HexCodes::ACK->value;
        $nak = HexCodes::NAK->value;
        $enq = HexCodes::ENQ->value;
        $stx = HexCodes::STX->value;
        $etx = HexCodes::ETX->value;
        $eot = HexCodes::EOT->value;

        $result = null;
        $inc = '';
        $order_string = null;
        if ($connection) {
            $idle = true;
            $order_request = false;
            $received = false;
            while (true) {
                while ($idle) {
                    $inc = socket_read($socket, 1024);
                    if ($inc == $enq) {
                        $resp = $ack;
                        socket_write($socket, $resp, strlen($resp));
                    } else {
                        $idle = false;
                    }
                }

                if ($inc == $stx) {
                    $resp = $ack;
                    socket_write($socket, $resp, strlen($resp));
                    $inc = socket_read($socket, 1024);
                }

                if (!in_array($inc, [$ack, $enq, $etx, $eot, $stx])) {
                    $inc = substr($inc, 0, -2);
                    $inc = hex2bin($inc);
                    $op_h = explode('|', $inc)[0];

                    if ($op_h == 'H') {
                        $resp = $ack;
                        socket_write($socket, $resp, strlen($resp));
                        $inc = socket_read($socket, 1024);
                    }

                    if ($op_h == 'Q') {
                        $order_request = true;
                        $barcode = explode('|', $inc)[2];
                        $barcode = preg_replace('/[^0-9]/', '', $barcode);
                        $order_string = $this->getOrderString($barcode);
                        if ($order_string) {
                            $resp = $ack;
                        } else {
                            $resp = $nak;
                        }
                        socket_write($socket, $resp, strlen($resp));
                        $inc = socket_read($socket, 1024);
                    }

                    if ($op_h == 'L') {
                        $resp = $ack;
                        socket_write($socket, $resp, strlen($resp));
                        $inc = socket_read($socket, 1024);
                    }
                }

                if ($inc == $etx) {
                    $resp = $ack;
                    socket_write($socket, $resp, strlen($resp));
                    $inc = socket_read($socket, 1024);
                }

                if ($inc == $eot) {
                    $received = true;
                }

                if ($received && $order_request && $order_string) {
                    socket_write($socket, $enq, strlen($enq));
                    $resp = socket_read($socket, 1024);

                    if ($resp == $ack) {
                        socket_write($socket, $stx, strlen($stx));
                        $resp = socket_read($socket, 1024);

                        if ($resp == $ack) {
                            $header = bin2hex($this->shortHeader());
                            $checksum = 0;
                            for ($i = 0; $i < strlen($header); $i++) {
                                $checksum += ord($header[$i]);
                            }
                            $checksum = $checksum & 0xFF;
                            $checksum = dechex($checksum);
                            $header = $header . $checksum;
                            socket_write($socket, $header, strlen($header));
                            $resp = socket_read($socket, 1024);

                            if ($resp == $ack) {
                                $patient = bin2hex($this->patientRecord());
                                $checksum = 0;
                                for ($i = 0; $i < strlen($patient); $i++) {
                                    $checksum += ord($patient[$i]);
                                }
                                $checksum = $checksum & 0xFF;
                                $checksum = dechex($checksum);
                                $patient = $patient . $checksum;
                                socket_write($socket, $patient, strlen($patient));
                                $resp = socket_read($socket, 1024);

                                if ($resp == $ack) {
                                    $order_string = bin2hex($order_string);
                                    $checksum = 0;
                                    for ($i = 0; $i < strlen($order_string); $i++) {
                                        $checksum += ord($order_string[$i]);
                                    }
                                    $checksum = $checksum & 0xFF;
                                    $checksum = dechex($checksum);
                                    $order_str = $order_string . $checksum;

                                    socket_write($socket, $order_str, strlen($order_str));
                                    $resp = socket_read($socket, 1024);

                                    if ($resp == $ack) {
                                        $terminator = bin2hex($this->terminator());
                                        $checksum = 0;
                                        for ($i = 0; $i < strlen($terminator); $i++) {
                                            $checksum += ord($terminator[$i]);
                                        }
                                        $checksum = $checksum & 0xFF;
                                        $checksum = dechex($checksum);
                                        $terminator = $terminator . $checksum;
                                        socket_write($socket, $terminator, strlen($terminator));
                                        $resp = socket_read($socket, 1024);

                                        if ($resp == $ack) {
                                            socket_write($socket, $etx, strlen($etx));
                                            $resp = socket_read($socket, 1024);

                                            if ($resp == $ack) {
                                                socket_write($socket, $eot, strlen($eot));
                                                $order_request = false;
                                                $received = false;
                                                $order_string = null;
                                                $idle = true;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        socket_close($socket);
        return $result;
    }

    private function getOrderString(string $barcode): ?string
    {
        $order = Order::where('order_barcode', $barcode)->first();
        if (!$order) {
           $order = Order::where('test_barcode', $barcode)->first();
        }

        return $order->order_record ?? null;
    }
//    public function processOrder_notWorking($order)
//    {
//        $ip = Analyzer::where('analyzer_id', $order['analyzer_id'])
//            ->where('lab_id', $order['lab_id'])
//            ->first()
//            ->local_ip;
//
//        $maxRetries = 3;
//        $retryCount = 0;
//        $timeout = 10; // Timeout in seconds
//        $connected = false;
//        $response = null;
//        $status = null;
//
//        while ($retryCount < $maxRetries && !$connected) {
//            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
//            $connection = @socket_connect($socket, $ip, 12000);
//
//            if ($connection) {
//                // Check if the line is not busy
//                $read = [$socket];
//                $write = null;
//                $except = null;
//
//                $ready = socket_select($read, $write, $except, $timeout);
//
//                if ($ready > 0) {
//                    // Line is not busy, send the ENQ message
//                    socket_write($socket, HexCodes::ENQ->value, strlen(HexCodes::ENQ->value));
//
//                    // Listen for a response
//                    $response = socket_read($socket, 2048); // Adjust the buffer size as needed
//
//                    // Check if the response is ACK
//                    if ($response === HexCodes::ACK->value) {
//                        $status = 'ACK received';
//                    } else {
//                        $status = 'Non-ACK response received';
//                    }
//
//                    $connected = true; // Successfully connected and communicated
//                } else {
//                    // Line is busy or timeout occurred
//                    $status = 'Line is busy or no response';
//                    $retryCount++;
//                    sleep($timeout); // Wait before retrying
//                }
//            } else {
//                // Handle connection failure
//                $status = 'Connection failed';
//                $retryCount++;
//                sleep($timeout); // Wait before retrying
//            }
//
//            // Close the socket connection
//            socket_close($socket);
//        }
//
//        if (!$connected) {
//            $status = 'Failed to connect after multiple attempts';
//        }
//
//        return response()->json(['status' => $status, 'response' => $response ?? 'No response']);
//    }
}



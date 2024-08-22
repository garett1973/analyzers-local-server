<?php

namespace App\Helpers;

class NetworkHelper
{
    public static function checkConnection($ip, $port, $timeout = 10): bool
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $connection = @socket_connect($socket, $ip, $port);

        if ($connection) {
            // Check if the line is not busy
            $read = [$socket];
            $write = null;
            $except = null;

            $ready = socket_select($read, $write, $except, $timeout);

            socket_close($socket);

            return $ready > 0;
        }

        socket_close($socket);
        return false;
    }
}


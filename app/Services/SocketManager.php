<?php

namespace App\Services;

class SocketManager
{
    protected $connections = [];

    public function addConnection($name, $connection)
    {
        $this->connections[$name] = $connection;
    }

    public function getConnection($name)
    {
        return $this->connections[$name] ?? null;
    }

    public function getAllConnections()
    {
        return $this->connections;
    }
}


<?php

namespace App\Http\Services;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Jobs\SendNewResultToMainServer;
use App\Models\Result;

class ResultService implements ResultServiceInterface
{

    public function createResult(array $result_data): bool
    {
        $result = new Result($result_data);
        $saved = $result->save();

        SendNewResultToMainServer::dispatch($result_data);

        return $saved;
    }
}

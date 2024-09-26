<?php

namespace App\Http\Services;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Models\Result;
use Illuminate\Http\JsonResponse;

class ResultService implements ResultServiceInterface
{

    public function createResult(array $result_data): JsonResponse
    {
        $result = new Result($result_data);
        $result->save();

        return new JsonResponse([
            'status' => 200,
            'message' => 'Result created successfully',
        ],
            200);
    }
}

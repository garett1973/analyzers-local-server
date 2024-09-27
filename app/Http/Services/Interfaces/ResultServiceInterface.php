<?php

namespace App\Http\Services\Interfaces;

use Illuminate\Http\JsonResponse;

interface ResultServiceInterface
{
    public function createResult(array $result_data): bool;
}

<?php

namespace App\Http\Services\Interfaces;

use Illuminate\Http\JsonResponse;

interface ImportServiceInterface
{
    public function importTests(array $tests_data): JsonResponse;

    public function importAnalyzers(array $analyzers_data): JsonResponse;

    public function importResult(array $results_data): JsonResponse;

    public function importAnalytes(array $analytes_data): JsonResponse;

    public function importAnalyzerTypes(array $analyzer_types_data): JsonResponse;
}

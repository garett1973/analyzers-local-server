<?php

namespace App\Http\Controllers;

use App\Http\Services\Interfaces\ImportServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    private ImportServiceInterface $importService;

    public function __construct(ImportServiceInterface $importService)
    {
        $this->importService = $importService;
    }

    public function importTests(Request $request): JsonResponse
    {
        $tests_data = $request->all();

        return $this->importService->importTests($tests_data);
    }

    public function importAnalyzers(Request $request): JsonResponse
    {
        $analyzers_data = $request->all();
        return $this->importService->importAnalyzers($analyzers_data);
    }

    public function importAnalyzerTypes(Request $request): JsonResponse
    {
        $analyzer_types_data = $request->all();
        return $this->importService->importAnalyzerTypes($analyzer_types_data);
    }

    public function importAnalytes(Request $request): JsonResponse
    {
        $analytes_data = $request->all();
        return $this->importService->importAnalytes($analytes_data);
    }

    public function importResult(Request $request): JsonResponse
    {
        $results_data = $request->all();
        return $this->importService->importResult($results_data);
    }
}

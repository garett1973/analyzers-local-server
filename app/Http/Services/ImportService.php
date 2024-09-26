<?php

namespace App\Http\Services;

use App\Http\Services\Interfaces\ImportServiceInterface;
use App\Models\Analyte;
use App\Models\Analyzer;
use App\Models\AnalyzerType;
use App\Models\Result;
use App\Models\Test;
use Illuminate\Http\JsonResponse;

class ImportService implements ImportServiceInterface
{

    public function importTests(array $tests_data): JsonResponse
    {
        $imported_tests = 0;
        foreach ($tests_data['tests'] as $test_data) {
            $test = Test::where('test_id', $test_data['test_id'])
                ->where('analyzer_id', $test_data['analyzer_id'])
                ->where('description', $test_data['description'])
                ->where('lab_id', $test_data['lab_id'])
                ->first();

            if ($test) {
                continue;
            }

            $test = new Test($test_data);
            $test->save();
            $imported_tests++;
        }

        return new JsonResponse([
            'status' => 200,
            'updated_tests' => $imported_tests,
        ],
            200);
    }

    public function importAnalyzers(array $analyzers_data): JsonResponse
    {

        $imported_analyzers = 0;
        foreach ($analyzers_data['analyzers'] as $analyzer_data) {
            $analyzer = Analyzer::where('analyzer_id', $analyzer_data['analyzer_id'])
                ->where('lab_id', $analyzer_data['lab_id'])
                ->first();

            if ($analyzer) {
                continue;
            }

            $analyzer = new Analyzer($analyzer_data);
            $analyzer->save();
            $imported_analyzers++;
        }

        return new JsonResponse([
            'status' => 200,
            'updated_analyzers' => $imported_analyzers,
        ],
            200);
    }

    public function importAnalytes(array $analytes_data): JsonResponse
    {

        $imported_analytes = 0;
        foreach ($analytes_data['analytes'] as $analyte_data) {
            // Check if analyte already exists
            $analyte = Analyte::where('analyte_id', $analyte_data['analyte_id'])
                ->where('analyzer_id', $analyte_data['analyzer_id'])
                ->where('test_id', $analyte_data['test_id'])
                ->where('lab_id', $analyte_data['lab_id'])
                ->first();

            if ($analyte) {
                continue;
            }

            $analyte = new Analyte($analyte_data);
            $analyte->save();
            $imported_analytes++;
        }

        return new JsonResponse([
            'status' => 200,
            'updated_analytes' => $imported_analytes,
        ],
            200);
    }

    public function importAnalyzerTypes(array $analyzer_types_data): JsonResponse
    {

        $updated_analyzer_types = 0;
        foreach ($analyzer_types_data['analyzer_types'] as $analyzer_type_data) {
            $analyzer_type = AnalyzerType::where('name', $analyzer_type_data['name'])
                ->where('group_id', $analyzer_type_data['group_id'])
                ->first();

            if ($analyzer_type) {
                continue;
            }

            $analyzer_type = new AnalyzerType($analyzer_type_data);
            $analyzer_type->save();
            $updated_analyzer_types++;
        }

        return new JsonResponse([
            'status' => 200,
            'updated_analyzer_types' => $updated_analyzer_types,
        ],
            200);
    }

    public function importResult(array $results_data): JsonResponse
    {
        $result = new Result($results_data);
        $result->save();

        return new JsonResponse([
            'status' => 200,
            'message' => 'Result imported successfully',
        ],
            200);
    }
}

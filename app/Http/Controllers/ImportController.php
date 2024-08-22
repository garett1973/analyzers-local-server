<?php

namespace App\Http\Controllers;

use App\Models\Analyzer;
use App\Models\AnalyzerType;
use App\Models\Test;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function importTests(Request $request): JsonResponse
    {
        $tests_data = $request->all();

        $updated_tests = 0;
        foreach ($tests_data['tests'] as $test_data) {
            // Check if test already exists
            $test = Test::where('test_id', $test_data['test_id'])
                ->where('sub_test_id', $test_data['sub_test_id'])
                ->where('analyzer_id', $test_data['analyzer_id'])
                ->where('description', $test_data['description'])
                ->where('lab_id', $test_data['lab_id'])
                ->first();

            if ($test) {
                continue;
            }

            $test = new Test($test_data);
            $test->save();
            $updated_tests++;
        }

        return new JsonResponse([
            'status' => 200,
            'updated_tests' => $updated_tests,
        ],
            200);
    }

    public function importAnalyzers(Request $request): JsonResponse
    {
        $analyzers_data = $request->all();

        $updated_analyzers = 0;
        foreach ($analyzers_data['analyzers'] as $analyzer_data) {
            // Check if analyzer already exists
            $analyzer = Analyzer::where('analyzer_id', $analyzer_data['analyzer_id'])
                ->where('lab_id', $analyzer_data['lab_id'])
                ->first();

            if ($analyzer) {
                continue;
            }

            $analyzer = new Analyzer($analyzer_data);
            $analyzer->save();
            $updated_analyzers++;
        }

        return new JsonResponse([
            'status' => 200,
            'updated_analyzers' => $updated_analyzers,
        ],
            200);
    }

    public function importAnalyzerTypes(Request $request): JsonResponse
    {
        $analyzer_types_data = $request->all();

//        return new JsonResponse([
//            'status' => 200,
//            'updated_analyzer_types' => $analyzer_types_data['analyzer_types'],
//        ],
//            200);

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
}

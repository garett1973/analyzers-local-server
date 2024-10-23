<?php

namespace App\Http\Services;

use App\Http\Services\Interfaces\ResultServiceInterface;
use App\Jobs\SendNewResultToMainServer;
use App\Models\Result;

class ResultService implements ResultServiceInterface
{

    public function createResult(array $result_data): bool
    {
        $existing_result = Result::where('barcode', $result_data['barcode'])
            ->where('analyte_id', $result_data['analyte_id'])
            ->where('analyte_name', $result_data['analyte_name'])
            ->first();

        if ($existing_result) {
            if($existing_result->result == $result_data['result']) {
                return true;
            }

            $existing_result->result = $result_data['result'];
            $existing_result->updated = 1;
            $existing_result->save();
            SendNewResultToMainServer::dispatch($result_data);

            return true;
        }

        $result = new Result($result_data);
        $saved = $result->save();

        SendNewResultToMainServer::dispatch($result_data);

        return $saved;
    }
}

<?php

namespace App\Jobs;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendNewResultToMainServer implements ShouldQueue
{
    use Queueable;

    private mixed $result;

    /**
     * Create a new job instance.
     */
    public function __construct($result)
    {
        $this->result = $result;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $main_server_url = env('MAIN_SERVER_URL') . '/api/result';
        echo 'Sending result to url: ' . $main_server_url . PHP_EOL;
        echo 'Result: ' . json_encode($this->result) . PHP_EOL;

        try {
            $response = Http::post($main_server_url, $this->result);
//            echo 'Response: ' . $response->body() . PHP_EOL;
            $response = json_decode($response->body(), true);

            if (isset($response['error'])) {
                echo 'Error: ' . $response['error'] . PHP_EOL;
            }

            if (isset($response['status']) && $response['status'] == 'success') {
                echo 'Result sent successfully.' . PHP_EOL;
            }
        } catch (Exception $e) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
        }
    }
}

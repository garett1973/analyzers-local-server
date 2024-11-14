<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ExtractAnalyteNames extends Command
{
    protected $signature = 'extract:analyte-names';
    protected $description = 'Extracts distinct analyte names and their first found order number';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $files = Storage::allFiles('public/aulis');
        $analyteData = [];

        foreach ($files as $file) {
            $contents = Storage::get($file);
            $lines = explode("\n", $contents);
            $orderNumber = '';

            foreach ($lines as $line) {
                if (str_starts_with($line, 'O|')) {
                    $orderNumber = explode('|', $line)[1];
                }

                if (str_starts_with($line, 'R|')) {
                    $analyteParts = explode('|', $line);
                    $analyteName = $analyteParts[1];

                    if (!isset($analyteData[$analyteName])) {
                        $analyteData[$analyteName] = $orderNumber;
                    }
                }
            }
        }

        foreach ($analyteData as $analyteName => $orderNumber) {
            $this->info("Analyte: $analyteName, Order Number: $orderNumber");
        }

        return 0;
    }
}

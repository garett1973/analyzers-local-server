<?php

require __DIR__ . '/vendor/autoload.php'; // Load Composer dependencies
require __DIR__ . '/bootstrap/app.php';   // Bootstrap Laravel application

$eot = "\x04";// End of Transmission

$inputFilePath = storage_path('app/public/dxl/DxI2024052210.log');
$outputFilePath = storage_path('app/public/dxl/messages_DxI2024052210.txt');



// Define keywords to filter for meaningful communication entries
$keywords = ['Packet received from:', 'Packet transmited to:'];

// Open the input file for reading
$inputFile = fopen($inputFilePath, 'r');
if (!$inputFile) {
    die("Failed to open the input file: $inputFilePath\n");
}

// Open the output file for writing
$outputFile = fopen($outputFilePath, 'w');
if (!$outputFile) {
    fclose($inputFile);
    die("Failed to open the output file: $outputFilePath\n");
}

// Filter lines containing the keywords and write them to the output file
while (($line = fgets($inputFile)) !== false) {

    // Trim the line to remove any trailing whitespace for accurate checking
    $trimmedLine = trim($line);

    // Skip lines that end with "04", "05", or "06"
    if (str_ends_with($trimmedLine, '04') ||
        str_ends_with($trimmedLine, '05') ||
        str_ends_with($trimmedLine, '06') ||
        str_ends_with($trimmedLine, '0D0A')
        ) {
        continue;
    }

    foreach ($keywords as $keyword) {
        if (str_contains($line, $keyword)) {
            $line_to_write = explode(' ', $line)[9];
            if (str_starts_with($line_to_write, "\x02")) {
                fwrite($outputFile, $line_to_write . "\n");
            } else {
                fwrite($outputFile, $line_to_write);
            }

            if (str_starts_with($line_to_write, "\x04")) {
                fwrite($outputFile, PHP_EOL);
            }

            break; // Move to the next line once a keyword is found
        }
    }
}

// Close both files
fclose($inputFile);
fclose($outputFile);

echo "Filtered log saved to: $outputFilePath\n";


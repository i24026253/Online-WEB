<?php
// Test FPDF
require 'lib/fpdf.php';
$pdf = new FPDF();
echo "✓ FPDF loaded successfully！<br>";

// Test PhpSpreadsheet（use Composer）
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;

try {
    $spreadsheet = new Spreadsheet();
    echo "✓ PhpSpreadsheet loaded successfully！<br>";
    echo "<p style='color:green; font-weight:bold;'>✅ All libraries are ready！</p>";
} catch (Exception $e) {
    echo "✗ Wrong: " . $e->getMessage();
}
?>
<?php
include 'connect.php';
include 'reports.php';

// Loading the export library
require 'lib/fpdf.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

// Exporting function
function exportReport($format, $reportData) {
    if ($format == 'pdf') {

        $fontDir = __DIR__ . '/lib/font';
        if (!is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);

            file_put_contents($fontDir . '/arial.php', '<?php $type="Core";$name="Arial";$up=-100;$ut=50;?>');
            file_put_contents($fontDir . '/arialb.php', '<?php $type="Core";$name="Arial-Bold";$up=-100;$ut=50;?>');
        }
        
        $pdf = new FPDF();
        $pdf->AddPage();
        

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Attendance Report', 0, 1, 'C');
        $pdf->Ln(10);
        
        // Header
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(50, 10, 'Student', 1);
        $pdf->Cell(50, 10, 'Course', 1);
        $pdf->Cell(40, 10, 'Date', 1);
        $pdf->Cell(40, 10, 'Status', 1);
        $pdf->Ln();
        
        // Data row
        $pdf->SetFont('Arial', '', 10);
        
        // Check if there is data
        $hasData = false;
        foreach ($reportData as $key => $row) {
            if ($key === 'summary') continue; 
            if (isset($row['student_name'])) {
                $hasData = true;
                $date = $row['SessionDate']; 
                $pdf->Cell(50, 10, substr($row['student_name'], 0, 20), 1);
                $pdf->Cell(50, 10, substr($row['CourseName'], 0, 20), 1);
                $pdf->Cell(40, 10, $date, 1);
                $pdf->Cell(40, 10, $row['Status'], 1);
                $pdf->Ln();
            }
        }
        
        // If there is no data, display a prompt
        if (!$hasData) {
            $pdf->SetFont('Arial', 'I', 12);
            $pdf->Cell(0, 10, 'No attendance records found for this period.', 0, 1, 'C');
        }
        
        // Summary information
        if (isset($reportData['summary'])) {
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, 'Summary', 0, 1);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(0, 10, 'Total Records: ' . $reportData['summary']['total_records'], 0, 1);
            $pdf->Cell(0, 10, 'Average Attendance: ' . $reportData['summary']['avg_percentage'] . '%', 0, 1);
        }
        
        $pdf->Output('D', 'attendance_report.pdf');
        exit;
        
    } elseif ($format == 'excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Set the header
        $sheet->setCellValue('A1', 'Student')
              ->setCellValue('B1', 'Course')
              ->setCellValue('C1', 'Date')
              ->setCellValue('D1', 'Status');
        
        // Header style
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        
        // Filling data
        $rowNum = 2;
        foreach ($reportData as $key => $row) {
            if ($key === 'summary') continue; 
            if (isset($row['student_name'])) {
                $date = $row['SessionDate']; 
                $sheet->setCellValue('A' . $rowNum, $row['student_name']);
                $sheet->setCellValue('B' . $rowNum, $row['CourseName']);
                $sheet->setCellValue('C' . $rowNum, $date);
                $sheet->setCellValue('D' . $rowNum, $row['Status']);
                $rowNum++;
            }
        }
        
        // Summary
        if (isset($reportData['summary'])) {
            $rowNum += 2;
            $sheet->setCellValue('A' . $rowNum, 'Total Records:');
            $sheet->setCellValue('B' . $rowNum, $reportData['summary']['total_records']);
            $rowNum++;
            $sheet->setCellValue('A' . $rowNum, 'Average Attendance:');
            $sheet->setCellValue('B' . $rowNum, $reportData['summary']['avg_percentage'] . '%');
        }
        
        // Automatically adjust column width
        foreach(range('A','D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Output
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="attendance_report.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        
    } elseif ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="attendance_report.csv"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        
        // Write BOM to support Chinese
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header
        fputcsv($output, ['Student', 'Course', 'Date', 'Status']);
        
        // Data
        foreach ($reportData as $key => $row) {
            if ($key === 'summary') continue; 
            if (isset($row['student_name'])) {
                $date = $row['SessionDate']; 
                fputcsv($output, [
                    $row['student_name'],
                    $row['CourseName'],
                    $date,
                    $row['Status']
                ]);
            }
        }
        
        // Summary
        if (isset($reportData['summary'])) {
            fputcsv($output, []);
            fputcsv($output, ['Total Records:', $reportData['summary']['total_records']]);
            fputcsv($output, ['Average Attendance:', $reportData['summary']['avg_percentage'] . '%']);
        }
        
        fclose($output);
        exit;
    }
}

// API Interface
if (isset($_GET['format']) && isset($_GET['period'])) {
    $period = $_GET['period'];
    $startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-1 month'));
    $endDate = $_GET['end'] ?? date('Y-m-d');
    $courseId = $_GET['course_id'] ?? null;
    $studentId = $_GET['student_id'] ?? null;
    
    $reportData = generateReport($conn, $period, $startDate, $endDate, $courseId, $studentId);
    
    exportReport($_GET['format'], $reportData);
}

sqlsrv_close($conn);
?>




<?php
include 'connect.php';

require 'lib/fpdf.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

/**
 * Generate report data with student name display
 */
function generateReportData($conn, $period, $startDate, $endDate, $courseId = null, $studentId = null, $lecturerId = null, $studentNumber = null) {
    $query = "
        SELECT
            s.StudentNumber,
            u.FirstName + ' ' + u.LastName AS student_name,
            c.CourseName,
            c.CourseCode,
            ats.SessionDate,
            ar.Status,
            ar.MarkedTime
        FROM dbo.Attendance_Records ar
        JOIN dbo.Students s ON ar.StudentID = s.StudentID
        JOIN dbo.Users u ON s.UserID = u.UserID
        JOIN dbo.Attendance_Sessions ats ON ar.SessionID = ats.SessionID
        JOIN dbo.Courses c ON ats.CourseID = c.CourseID
        WHERE 1=1
    ";
    
    $params = [];
    
    // ✅ FIXED: Date filtering for monthly period - same fix as reports.php
    if ($period === 'monthly' && $startDate) {
        // Extract year and month from the date
        if (strlen($startDate) == 7) {
            // Format: YYYY-MM
            $year = substr($startDate, 0, 4);
            $month = substr($startDate, 5, 2);
        } elseif (strlen($startDate) == 10) {
            // Format: YYYY-MM-DD
            $year = substr($startDate, 0, 4);
            $month = substr($startDate, 5, 2);
        } else {
            // Fallback
            $dateParts = explode('-', $startDate);
            $year = $dateParts[0];
            $month = $dateParts[1];
        }
        
        // Use YEAR and MONTH functions for matching
        $query .= " AND YEAR(ar.MarkedTime) = ? AND MONTH(ar.MarkedTime) = ?";
        $params[] = (int)$year;
        $params[] = (int)$month;
        
    } elseif ($period === 'weekly' && $startDate) {
        $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
        
        $query .= " AND CAST(ar.MarkedTime AS DATE) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    } elseif ($startDate && $endDate) {
        $query .= " AND CAST(ar.MarkedTime AS DATE) BETWEEN ? AND ?";
        $params[] = $startDate;
        $params[] = $endDate;
    }
    
    if ($courseId) {
        $query .= " AND c.CourseID = ?";
        $params[] = $courseId;
    }
    
    if ($studentId) {
        $query .= " AND s.StudentID = ?";
        $params[] = $studentId;
    }
    
    if ($studentNumber) {
        $query .= " AND s.StudentNumber LIKE ?";
        $params[] = '%' . $studentNumber . '%';
    }
    
    if ($lecturerId) {
        $query .= " AND ats.LecturerID = ?";
        $params[] = $lecturerId;
    }
    
    $query .= " ORDER BY ar.MarkedTime DESC";
    
    $stmt = sqlsrv_query($conn, $query, $params);
    
    if ($stmt === false) {
        return [
            'records' => [],
            'summary' => [
                'total_records' => 0,
                'present_count' => 0,
                'absent_count' => 0,
                'late_count' => 0
            ]
        ];
    }
    
    $report = [];
    $totalRecords = 0;
    $presentCount = 0;
    $absentCount = 0;
    $lateCount = 0;
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $totalRecords++;
        
        if ($row['Status'] === 'Present') {
            $presentCount++;
        } elseif ($row['Status'] === 'Absent') {
            $absentCount++;
        } elseif ($row['Status'] === 'Late') {
            $lateCount++;
        }
        
        $markedTime = $row['MarkedTime'];
        if (is_object($markedTime) && method_exists($markedTime, 'format')) {
            $markedTime = $markedTime->format('Y-m-d H:i:s');
        }
        
        $report[] = [
            'StudentNumber' => $row['StudentNumber'],
            'student_name' => $row['student_name'],
            'CourseName' => $row['CourseName'],
            'CourseCode' => $row['CourseCode'],
            'Status' => $row['Status'],
            'MarkedTime' => $markedTime
        ];
    }
    
    sqlsrv_free_stmt($stmt);
    
    return [
        'records' => $report,
        'summary' => [
            'total_records' => $totalRecords,
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'late_count' => $lateCount
        ]
    ];
}

// Exporting function
function exportReport($format, $reportData, $period, $startDate, $endDate, $courseFilter, $studentFilter) {
    if ($format == 'pdf') {
        $fontDir = __DIR__ . '/lib/font';
        if (!is_dir($fontDir)) {
            mkdir($fontDir, 0755, true);
            file_put_contents($fontDir . '/arial.php', '<?php $type="Core";$name="Arial";$up=-100;$ut=50;?>');
            file_put_contents($fontDir . '/arialb.php', '<?php $type="Core";$name="Arial-Bold";$up=-100;$ut=50;?>');
        }
      
        $pdf = new FPDF();
        $pdf->AddPage();
      
        // Title
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Attendance Report', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Filter Information
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, 'Time Period: ' . ucfirst($period), 0, 1);
        $pdf->Cell(0, 6, 'Date Range: ' . $startDate . ' to ' . $endDate, 0, 1);
        if ($courseFilter) {
            $pdf->Cell(0, 6, 'Course: ' . $courseFilter, 0, 1);
        }
        if ($studentFilter) {
            $pdf->Cell(0, 6, 'Student: ' . $studentFilter, 0, 1);
        }
        $pdf->Ln(5);
      
        $hasData = isset($reportData['records']) && count($reportData['records']) > 0;
        
        if ($hasData) {
            // Table Header
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(60, 8, 'Student Name', 1);
            $pdf->Cell(50, 8, 'Course', 1);
            $pdf->Cell(25, 8, 'Status', 1);
            $pdf->Cell(55, 8, 'Marked Time', 1);
            $pdf->Ln();
          
            // Data rows
            $pdf->SetFont('Arial', '', 8);
          
            foreach ($reportData['records'] as $row) {
                $pdf->Cell(60, 7, substr($row['student_name'], 0, 25), 1);
                $pdf->Cell(50, 7, substr($row['CourseCode'] . ' - ' . $row['CourseName'], 0, 25), 1);
                $pdf->Cell(25, 7, $row['Status'], 1);
                $pdf->Cell(55, 7, $row['MarkedTime'], 1);
                $pdf->Ln();
            }
            
            // Summary
            if (isset($reportData['summary'])) {
                $pdf->Ln(5);
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->Cell(0, 8, 'Summary', 0, 1);
                $pdf->SetFont('Arial', '', 10);
                $pdf->Cell(0, 6, 'Total Records: ' . $reportData['summary']['total_records'], 0, 1);
                $pdf->Cell(0, 6, 'Present: ' . $reportData['summary']['present_count'], 0, 1);
                $pdf->Cell(0, 6, 'Absent: ' . $reportData['summary']['absent_count'], 0, 1);
                $pdf->Cell(0, 6, 'Late: ' . $reportData['summary']['late_count'], 0, 1);
            }
        } else {
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->Cell(0, 10, 'No attendance records found for this period.', 0, 1, 'C');
        }
      
        $pdf->Output('D', 'attendance_report.pdf');
        exit;
      
    } elseif ($format == 'excel') {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Title
        $sheet->setCellValue('A1', 'Attendance Report');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Filter Information
        $row = 3;
        $sheet->setCellValue('A' . $row, 'Time Period:');
        $sheet->setCellValue('B' . $row, ucfirst($period));
        $row++;
        $sheet->setCellValue('A' . $row, 'Date Range:');
        $sheet->setCellValue('B' . $row, $startDate . ' to ' . $endDate);
        $row++;
        if ($courseFilter) {
            $sheet->setCellValue('A' . $row, 'Course:');
            $sheet->setCellValue('B' . $row, $courseFilter);
            $row++;
        }
        if ($studentFilter) {
            $sheet->setCellValue('A' . $row, 'Student:');
            $sheet->setCellValue('B' . $row, $studentFilter);
            $row++;
        }
        $row += 2;
      
        // Header
        $sheet->setCellValue('A' . $row, 'Student Name')
              ->setCellValue('B' . $row, 'Course')
              ->setCellValue('C' . $row, 'Status')
              ->setCellValue('D' . $row, 'Marked Time');
        $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
        $row++;
      
        // Data
        if (isset($reportData['records']) && count($reportData['records']) > 0) {
            foreach ($reportData['records'] as $record) {
                $sheet->setCellValue('A' . $row, $record['student_name']);
                $sheet->setCellValue('B' . $row, $record['CourseCode'] . ' - ' . $record['CourseName']);
                $sheet->setCellValue('C' . $row, $record['Status']);
                $sheet->setCellValue('D' . $row, $record['MarkedTime']);
                $row++;
            }
            
            // Summary
            if (isset($reportData['summary'])) {
                $row += 2;
                $sheet->setCellValue('A' . $row, 'Total Records:');
                $sheet->setCellValue('B' . $row, $reportData['summary']['total_records']);
                $row++;
                $sheet->setCellValue('A' . $row, 'Present:');
                $sheet->setCellValue('B' . $row, $reportData['summary']['present_count']);
                $row++;
                $sheet->setCellValue('A' . $row, 'Absent:');
                $sheet->setCellValue('B' . $row, $reportData['summary']['absent_count']);
                $row++;
                $sheet->setCellValue('A' . $row, 'Late:');
                $sheet->setCellValue('B' . $row, $reportData['summary']['late_count']);
            }
        } else {
            $sheet->setCellValue('A' . $row, 'No attendance records found for this period.');
        }
      
        foreach(range('A','D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
      
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
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
      
        // Title and filters
        fputcsv($output, ['Attendance Report']);
        fputcsv($output, []);
        fputcsv($output, ['Time Period:', ucfirst($period)]);
        fputcsv($output, ['Date Range:', $startDate . ' to ' . $endDate]);
        if ($courseFilter) {
            fputcsv($output, ['Course:', $courseFilter]);
        }
        if ($studentFilter) {
            fputcsv($output, ['Student:', $studentFilter]);
        }
        fputcsv($output, []);
      
        // Header
        fputcsv($output, ['Student Name', 'Course', 'Status', 'Marked Time']);
      
        // Data
        if (isset($reportData['records']) && count($reportData['records']) > 0) {
            foreach ($reportData['records'] as $row) {
                fputcsv($output, [
                    $row['student_name'],
                    $row['CourseCode'] . ' - ' . $row['CourseName'],
                    $row['Status'],
                    $row['MarkedTime']
                ]);
            }
            
            // Summary
            if (isset($reportData['summary'])) {
                fputcsv($output, []);
                fputcsv($output, ['Total Records:', $reportData['summary']['total_records']]);
                fputcsv($output, ['Present:', $reportData['summary']['present_count']]);
                fputcsv($output, ['Absent:', $reportData['summary']['absent_count']]);
                fputcsv($output, ['Late:', $reportData['summary']['late_count']]);
            }
        } else {
            fputcsv($output, ['No attendance records found for this period.']);
        }
      
        fclose($output);
        exit;
    }
}

// API Interface
if (isset($_GET['format']) && isset($_GET['period'])) {
    $period = $_GET['period'];
    $startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end'] ?? date('Y-m-d');
    $courseId = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
    $studentId = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : null;
    $lecturerId = isset($_GET['lecturer_id']) && $_GET['lecturer_id'] !== '' ? (int)$_GET['lecturer_id'] : null;
    $studentNumber = isset($_GET['student_number']) && $_GET['student_number'] !== '' ? $_GET['student_number'] : null;
  
    // Get course name for display
    $courseFilter = '';
    if ($courseId) {
        $courseQuery = "SELECT CourseCode, CourseName FROM dbo.Courses WHERE CourseID = ?";
        $courseStmt = sqlsrv_query($conn, $courseQuery, [$courseId]);
        if ($courseStmt) {
            $courseRow = sqlsrv_fetch_array($courseStmt, SQLSRV_FETCH_ASSOC);
            if ($courseRow) {
                $courseFilter = $courseRow['CourseCode'] . ' - ' . $courseRow['CourseName'];
            }
            sqlsrv_free_stmt($courseStmt);
        }
    }
    
    // Get student name for display
    $studentFilter = '';
    if ($studentNumber) {
        $studentQuery = "SELECT u.FirstName, u.LastName, s.StudentNumber 
                        FROM dbo.Students s
                        JOIN dbo.Users u ON s.UserID = u.UserID
                        WHERE s.StudentNumber LIKE ?";
        $studentStmt = sqlsrv_query($conn, $studentQuery, ['%' . $studentNumber . '%']);
        if ($studentStmt) {
            $studentRow = sqlsrv_fetch_array($studentStmt, SQLSRV_FETCH_ASSOC);
            if ($studentRow) {
                $studentFilter = $studentRow['FirstName'] . ' ' . $studentRow['LastName'] . ' (' . $studentRow['StudentNumber'] . ')';
            }
            sqlsrv_free_stmt($studentStmt);
        }
    }
  
    $reportData = generateReportData($conn, $period, $startDate, $endDate, $courseId, $studentId, $lecturerId, $studentNumber);
  
    exportReport($_GET['format'], $reportData, $period, $startDate, $endDate, $courseFilter, $studentFilter);
}

sqlsrv_close($conn);
?>
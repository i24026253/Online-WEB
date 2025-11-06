<?php
include 'connect.php';
require 'lib/fpdf.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function generateReportData($conn, $period, $startDate, $endDate, $courseId = null, $studentId = null, $lecturerId = null, $studentNumber = null) {
    
    $query = "
        SELECT
            s.StudentNumber,
            u.FirstName + ' ' + u.LastName AS student_name,
            c.CourseName,
            c.CourseCode,
            am.Date as SessionDate,
            ar.Status,
            ar.MarkedTime
        FROM dbo.Attendance_Records ar
        JOIN dbo.Students s ON ar.StudentID = s.StudentID
        JOIN dbo.Users u ON s.UserID = u.UserID
        JOIN dbo.Attendance_Mark am ON ar.MarkID = am.MarkID
        JOIN dbo.Courses c ON am.CourseID = c.CourseID
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($period === 'monthly' && $startDate) {
        if (strlen($startDate) === 7) {
            $startDate = $startDate . '-01';
        }
        
        $firstDay = date('Y-m-01', strtotime($startDate));
        $query .= " AND ar.MarkedTime >= ? AND ar.MarkedTime < ?";
        $params[] = $firstDay . ' 00:00:00';
        $params[] = date('Y-m-01 00:00:00', strtotime($firstDay . ' +1 month'));
        
    } elseif ($period === 'weekly' && $startDate) {
        $endDate = date('Y-m-d', strtotime($startDate . ' +6 days'));
        $query .= " AND ar.MarkedTime >= ? AND ar.MarkedTime < ?";
        $params[] = $startDate . ' 00:00:00';
        $params[] = date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day'));
        
    } elseif ($startDate && $endDate) {
        $query .= " AND ar.MarkedTime >= ? AND ar.MarkedTime < ?";
        $params[] = $startDate . ' 00:00:00';
        $params[] = date('Y-m-d 00:00:00', strtotime($endDate . ' +1 day'));
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
        $query .= " AND EXISTS (
            SELECT 1 FROM dbo.Course_Assignments ca 
            WHERE ca.CourseID = c.CourseID 
            AND ca.LecturerID = ? 
            AND ca.IsActive = 1
        )";
        $params[] = $lecturerId;
    }
    
    $query .= " ORDER BY ar.MarkedTime DESC";
    
    $stmt = sqlsrv_query($conn, $query, $params);
    
    if ($stmt === false) {
        error_log("Export SQL Error: " . print_r(sqlsrv_errors(), true));
        return [
            'records' => [],
            'summary' => [
                'total_records' => 0,
                'present_count' => 0,
                'absent_count' => 0,
            ]
        ];
    }
    
    $report = [];
    $totalRecords = 0;
    $presentCount = 0;
    $absentCount = 0;
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $totalRecords++;
        
        if ($row['Status'] === 'Present') {
            $presentCount++;
        } elseif ($row['Status'] === 'Absent') {
            $absentCount++;
        }
        
        $markedTime = $row['MarkedTime'];
        if (is_object($markedTime) && method_exists($markedTime, 'format')) {
            $markedTime = $markedTime->format('Y-m-d H:i:s');
        }
        
        $sessionDate = $row['SessionDate'];
        if (is_object($sessionDate) && method_exists($sessionDate, 'format')) {
            $sessionDate = $sessionDate->format('Y-m-d');
        }
        
        $report[] = [
            'StudentNumber' => $row['StudentNumber'],
            'student_name' => $row['student_name'],
            'CourseName' => $row['CourseName'],
            'CourseCode' => $row['CourseCode'],
            'SessionDate' => $sessionDate,
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
        ]
    ];
}

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
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, 'Attendance Report', 0, 1, 'C');
        $pdf->Ln(5);
        
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
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(60, 8, 'Student Name', 1);
            $pdf->Cell(50, 8, 'Course', 1);
            $pdf->Cell(25, 8, 'Status', 1);
            $pdf->Cell(55, 8, 'Marked Time', 1);
            $pdf->Ln();
          
            $pdf->SetFont('Arial', '', 8);
            foreach ($reportData['records'] as $row) {
                $pdf->Cell(60, 7, substr($row['student_name'], 0, 25), 1);
                $pdf->Cell(50, 7, substr($row['CourseCode'] . ' - ' . $row['CourseName'], 0, 25), 1);
                $pdf->Cell(25, 7, $row['Status'], 1);
                $pdf->Cell(55, 7, $row['MarkedTime'], 1);
                $pdf->Ln();
            }
            
            if (isset($reportData['summary'])) {
                $pdf->Ln(5);
                $pdf->SetFont('Arial', 'B', 11);
                $pdf->Cell(0, 8, 'Summary', 0, 1);
                $pdf->SetFont('Arial', '', 10);
                $pdf->Cell(0, 6, 'Total Records: ' . $reportData['summary']['total_records'], 0, 1);
                $pdf->Cell(0, 6, 'Present: ' . $reportData['summary']['present_count'], 0, 1);
                $pdf->Cell(0, 6, 'Absent: ' . $reportData['summary']['absent_count'], 0, 1);
            }
        } else {
            $pdf->SetFont('Arial', 'I', 11);
            $pdf->Cell(0, 10, 'No attendance records found for this period.', 0, 1, 'C');
        }
      
        $pdf->Output('D', 'attendance_report.pdf');
        exit;
      
    } elseif ($format == 'excel') {
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $sheet->setCellValue('A1', 'Attendance Report');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        $row = 3;
        $sheet->setCellValue('A' . $row, 'Time Period:')->setCellValue('B' . $row, ucfirst($period));
        $row++;
        $sheet->setCellValue('A' . $row, 'Date Range:')->setCellValue('B' . $row, $startDate . ' to ' . $endDate);
        $row++;
        if ($courseFilter) {
            $sheet->setCellValue('A' . $row, 'Course:')->setCellValue('B' . $row, $courseFilter);
            $row++;
        }
        if ($studentFilter) {
            $sheet->setCellValue('A' . $row, 'Student:')->setCellValue('B' . $row, $studentFilter);
            $row++;
        }
        $row += 2;
      
        $sheet->setCellValue('A' . $row, 'Student Name')
              ->setCellValue('B' . $row, 'Course')
              ->setCellValue('C' . $row, 'Status')
              ->setCellValue('D' . $row, 'Marked Time');
        $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);
        $row++;
      
        if (isset($reportData['records']) && count($reportData['records']) > 0) {
            foreach ($reportData['records'] as $record) {
                $sheet->setCellValue('A' . $row, $record['student_name'])
                      ->setCellValue('B' . $row, $record['CourseCode'] . ' - ' . $record['CourseName'])
                      ->setCellValue('C' . $row, $record['Status'])
                      ->setCellValue('D' . $row, $record['MarkedTime']);
                $row++;
            }
            
            if (isset($reportData['summary'])) {
                $row += 2;
                $sheet->setCellValue('A' . $row, 'Total Records:')
                      ->setCellValue('B' . $row, $reportData['summary']['total_records']);
                $row++;
                $sheet->setCellValue('A' . $row, 'Present:')
                      ->setCellValue('B' . $row, $reportData['summary']['present_count']);
                $row++;
                $sheet->setCellValue('A' . $row, 'Absent:')
                      ->setCellValue('B' . $row, $reportData['summary']['absent_count']);
            }
        } else {
            $sheet->setCellValue('A' . $row, 'No attendance records found for this period.');
        }
      
        foreach(range('A','D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $filename = 'attendance_report_' . date('Ymd_His') . '.xlsx';
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
        
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);
        
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        unset($writer);
        
        if (!file_exists($tempFile)) {
            die('Error: Failed to create Excel file');
        }
        
        $fileSize = filesize($tempFile);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        
        readfile($tempFile);
        
        @unlink($tempFile);
        
        exit;
      
    } elseif ($format == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="attendance_report.csv"');
        header('Cache-Control: max-age=0');
      
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
      
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
      
        fputcsv($output, ['Student Name', 'Course', 'Status', 'Marked Time']);
      
        if (isset($reportData['records']) && count($reportData['records']) > 0) {
            foreach ($reportData['records'] as $row) {
                fputcsv($output, [
                    $row['student_name'],
                    $row['CourseCode'] . ' - ' . $row['CourseName'],
                    $row['Status'],
                    $row['MarkedTime']
                ]);
            }
            
            if (isset($reportData['summary'])) {
                fputcsv($output, []);
                fputcsv($output, ['Total Records:', $reportData['summary']['total_records']]);
                fputcsv($output, ['Present:', $reportData['summary']['present_count']]);
                fputcsv($output, ['Absent:', $reportData['summary']['absent_count']]);
            }
        } else {
            fputcsv($output, ['No attendance records found for this period.']);
        }
      
        fclose($output);
        exit;
    }
}

if (isset($_GET['format']) && isset($_GET['period'])) {
    $period = $_GET['period'];
    $startDate = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end'] ?? date('Y-m-d');
    $courseId = isset($_GET['course_id']) && $_GET['course_id'] !== '' ? (int)$_GET['course_id'] : null;
    $studentId = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : null;
    $lecturerId = isset($_GET['lecturer_id']) && $_GET['lecturer_id'] !== '' ? (int)$_GET['lecturer_id'] : null;
    $studentNumber = isset($_GET['student_number']) && $_GET['student_number'] !== '' ? $_GET['student_number'] : null;
  
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
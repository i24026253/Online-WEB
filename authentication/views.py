import pyodbc
import requests
from django.shortcuts import render, redirect
from django.contrib import messages

# 🔹 LOGIN VIEW
def login_view(request):
    """Login using dbo.Users table in SQL Server"""
    if request.method == 'POST':
        username = request.POST.get('username')
        password = request.POST.get('password')

        try:
            conn = pyodbc.connect(
                'DRIVER={ODBC Driver 17 for SQL Server};'
                'SERVER=DESKTOP-L8AJQU8\SQLEXPRESS;'
                'DATABASE=Attendance1;'
                'Trusted_Connection=yes;'
            )
            cursor = conn.cursor()

            query = """
                SELECT Username, PasswordHash, Role, FirstName, LastName
                FROM dbo.Users
                WHERE Username = ?
            """
            cursor.execute(query, (username,))
            row = cursor.fetchone()
            conn.close()

            if row:
                username_db, password_db, role_db, first_name, last_name = row

                if password == password_db:
                    request.session['username'] = username_db
                    request.session['role'] = role_db.lower()
                    request.session['first_name'] = first_name
                    request.session['last_name'] = last_name

                    if role_db.lower() == 'admin':
                        return redirect('admin_dashboard')
                    elif role_db.lower() == 'lecturer':
                        return redirect('lecturer_dashboard')
                    elif role_db.lower() == 'student':
                        return redirect('student_dashboard')
                    else:
                        messages.warning(request, 'Unknown role.')
                else:
                    messages.error(request, 'Invalid password.')
            else:
                messages.error(request, 'User not found.')

        except Exception as e:
            messages.error(request, f"Database error: {e}")

    return render(request, 'login.html')

# 🔹 LOGOUT VIEW
def logout_view(request):
    request.session.flush()
    messages.success(request, "Logged out successfully.")
    return redirect('login')

# 🔹 GENERAL DASHBOARD REDIRECTOR
def dashboard_view(request):
    """Redirect users to their respective dashboards based on role."""
    role = request.session.get('role')
    if not role:
        return redirect('login')

    if role == 'admin':
        return redirect('admin_dashboard')
    elif role == 'lecturer':
        return redirect('lecturer_dashboard')
    elif role == 'student':
        return redirect('student_dashboard')
    else:
        return redirect('login')

# 🔹 ADMIN DASHBOARD (FIXED)
def admin_dashboard(request):
    username = request.session.get('username', 'Unknown')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')
    
    total_users = 0
    total_students = 0
    total_lecturers = 0
    total_courses = 0
    pending_enrollments = 0
    recent_attendance_records = []
    lecturers_per_course = []
    students_per_course = []
    
    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-L8AJQU8\SQLEXPRESS;'
            'DATABASE=Attendance1;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        
        # Basic statistics
        cursor.execute("SELECT COUNT(*) FROM dbo.Users")
        total_users = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM dbo.Students WHERE IsActive = 1")
        total_students = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM dbo.Lecturers WHERE IsActive = 1")
        total_lecturers = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM dbo.Courses WHERE IsActive = 1")
        total_courses = cursor.fetchone()[0]
        
        # Pending enrollments count
        cursor.execute("SELECT COUNT(*) FROM dbo.Enrollments WHERE Status IN ('Pending Enroll', 'Pending Drop')")
        pending_enrollments = cursor.fetchone()[0]
        
        cursor.execute("""
            SELECT TOP 10
                c.CourseCode,
                c.CourseName,
                ISNULL(u.FirstName + ' ' + u.LastName, 'Not Assigned') as lecturer_name
            FROM dbo.Courses c
            LEFT JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID AND ca.IsActive = 1
            LEFT JOIN dbo.Lecturers l ON ca.LecturerID = l.LecturerID
            LEFT JOIN dbo.Users u ON l.UserID = u.UserID
            WHERE c.IsActive = 1
            ORDER BY c.CourseCode
        """)
        
        lecturers_per_course = []
        for row in cursor.fetchall():
            lecturers_per_course.append({
                'course': row.CourseCode,
                'course_name': row.CourseName,
                'lecturer': row.lecturer_name
            })
        
        # Students per course
        cursor.execute("""
            SELECT TOP 10
                c.CourseCode,
                c.CourseName,
                COUNT(DISTINCT e.StudentID) as student_count
            FROM dbo.Courses c
            LEFT JOIN dbo.Enrollments e ON c.CourseID = e.CourseID AND e.Status = 'Active'
            WHERE c.IsActive = 1
            GROUP BY c.CourseCode, c.CourseName
            ORDER BY student_count DESC, c.CourseCode
        """)
        
        students_per_course = []
        for row in cursor.fetchall():
            students_per_course.append({
                'course': row.CourseCode,
                'count': row.student_count or 0
            })
        
        cursor.execute("""
            SELECT TOP 10
                s.StudentNumber,
                c.CourseCode,
                c.CourseName,
                ar.MarkedTime,
                ar.Status
            FROM dbo.Attendance_Records ar
            JOIN dbo.Students s ON ar.StudentID = s.StudentID
            JOIN dbo.Attendance_Mark am ON ar.MarkID = am.MarkID
            JOIN dbo.Courses c ON am.CourseID = c.CourseID
            ORDER BY ar.MarkedTime DESC
        """)
        
        recent_attendance_records = []
        for row in cursor.fetchall():
            recent_attendance_records.append({
                'student_number': row.StudentNumber,
                'course_code': row.CourseCode,
                'course_name': row.CourseName,
                'marked_time': row.MarkedTime,
                'status': row.Status
            })
        
        conn.close()
    except Exception as e:
        messages.warning(request, f"Stats fetch error: {e} - Using defaults.")
        print(f"Admin dashboard error: {e}")
    
    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'dashboard_title': f"<i class='fas fa-tachometer-alt me-2'></i>Admin Dashboard - Welcome, {first_name} {last_name}!",
        'total_users': total_users,
        'total_students': total_students,
        'total_lecturers': total_lecturers,
        'total_courses': total_courses,
        'pending_enrollments': pending_enrollments,
        'lecturers_per_course': lecturers_per_course,
        'students_per_course': students_per_course,
        'recent_attendance_records': recent_attendance_records,
    }
    
    return render(request, 'dashboard/admin_dashboard.html', context)


# 🔹 LECTURER DASHBOARD 
def lecturer_dashboard(request):
    username = request.session.get('username', 'Unknown')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')

    total_courses = 0
    total_students = 0
    total_sessions = 0
    sessions_this_week = 0
    my_courses = []
    recent_sessions = []
    attendance_records_count = 0

    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-L8AJQU8\SQLEXPRESS;'
            'DATABASE=Attendance1;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        
        cursor.execute("""
            SELECT l.LecturerID 
            FROM dbo.Lecturers l 
            JOIN dbo.Users u ON l.UserID = u.UserID 
            WHERE u.Username = ?
        """, (username,))
        lecturer_row = cursor.fetchone()
        
        if lecturer_row:
            lecturer_id = lecturer_row[0]
            
            # Total courses teaching
            cursor.execute("""
                SELECT COUNT(*) 
                FROM dbo.Course_Assignments 
                WHERE LecturerID = ? AND IsActive = 1
            """, (lecturer_id,))
            total_courses = cursor.fetchone()[0]
            
            # Total unique students across all courses
            cursor.execute("""
                SELECT COUNT(DISTINCT e.StudentID) 
                FROM dbo.Enrollments e
                JOIN dbo.Course_Assignments ca ON e.CourseID = ca.CourseID
                WHERE ca.LecturerID = ? 
                AND ca.IsActive = 1 
                AND e.Status = 'Active'
            """, (lecturer_id,))
            total_students = cursor.fetchone()[0]
            
            cursor.execute("""
                SELECT COUNT(DISTINCT am.MarkID)
                FROM dbo.Attendance_Mark am
                JOIN dbo.Course_Assignments ca ON am.CourseID = ca.CourseID
                WHERE ca.LecturerID = ? AND ca.IsActive = 1
            """, (lecturer_id,))
            total_sessions = cursor.fetchone()[0]
            
            # Count attendance marks created this week
            from datetime import datetime, timedelta
            week_start = datetime.now() - timedelta(days=datetime.now().weekday())
            cursor.execute("""
                SELECT COUNT(*)
                FROM dbo.Attendance_Mark am
                JOIN dbo.Course_Assignments ca ON am.CourseID = ca.CourseID
                WHERE ca.LecturerID = ? AND am.[Date] >= ?
            """, (lecturer_id, week_start))
            attendance_records_count = cursor.fetchone()[0]
            
            cursor.execute("""
                SELECT 
                    c.CourseID,
                    c.CourseCode,
                    c.CourseName,
                    COUNT(DISTINCT e.StudentID) as enrolled_count,
                    COUNT(DISTINCT am.MarkID) as session_count,
                    ISNULL(AVG(CASE WHEN ar.Status = 'Present' THEN 100.0 ELSE 0 END), 0) as avg_attendance
                FROM dbo.Courses c
                JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                LEFT JOIN dbo.Enrollments e ON c.CourseID = e.CourseID AND e.Status = 'Active'
                LEFT JOIN dbo.Attendance_Mark am ON c.CourseID = am.CourseID
                LEFT JOIN dbo.Attendance_Records ar ON am.MarkID = ar.MarkID
                WHERE ca.LecturerID = ? AND ca.IsActive = 1
                GROUP BY c.CourseID, c.CourseCode, c.CourseName
                ORDER BY c.CourseCode
            """, (lecturer_id,))
            
            my_courses = [
                {
                    'CourseID': row.CourseID,
                    'CourseCode': row.CourseCode,
                    'CourseName': row.CourseName,
                    'enrolled_count': row.enrolled_count or 0,
                    'session_count': row.session_count or 0,
                    'avg_attendance': round(row.avg_attendance or 0, 1)
                }
                for row in cursor.fetchall()
            ]
            
            cursor.execute("""
                SELECT TOP 10
                    am.MarkID as SessionID,
                    am.Date as SessionDate,
                    am.MarkedTime as SessionStartTime,
                    NULL as SessionEndTime,
                    'Lecture' as SessionType,
                    NULL as Location,
                    1 as IsActive,
                    c.CourseCode,
                    c.CourseName,
                    COUNT(ar.AttendanceID) as total_count,
                    SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) as present_count,
                    ISNULL(
                        CAST(SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) AS FLOAT) / 
                        NULLIF(COUNT(ar.AttendanceID), 0) * 100, 
                        0
                    ) as attendance_rate
                FROM dbo.Attendance_Mark am
                JOIN dbo.Courses c ON am.CourseID = c.CourseID
                JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                LEFT JOIN dbo.Attendance_Records ar ON am.MarkID = ar.MarkID
                WHERE ca.LecturerID = ? AND ca.IsActive = 1
                GROUP BY 
                    am.MarkID, am.Date, am.MarkedTime, 
                    c.CourseCode, c.CourseName
                ORDER BY am.Date DESC, am.MarkedTime DESC
            """, (lecturer_id,))
            
            recent_sessions = [
                {
                    'SessionID': row.SessionID,
                    'SessionDate': row.SessionDate,
                    'SessionStartTime': row.SessionStartTime,
                    'SessionEndTime': row.SessionEndTime,
                    'SessionType': row.SessionType,
                    'Location': row.Location,
                    'IsActive': row.IsActive,
                    'CourseCode': row.CourseCode,
                    'CourseName': row.CourseName,
                    'total_count': row.total_count or 0,
                    'present_count': row.present_count or 0,
                    'attendance_rate': round(row.attendance_rate or 0, 1)
                }
                for row in cursor.fetchall()
            ]
        
        conn.close()
    except Exception as e:
        messages.warning(request, f"Error loading dashboard data: {e}")
        print(f"Lecturer dashboard error: {e}")

    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'total_courses': total_courses,
        'total_students': total_students,
        'total_sessions': total_sessions,
        'sessions_this_week': sessions_this_week,
        'my_courses': my_courses,
        'recent_sessions': recent_sessions,
        'attendance_records_count': attendance_records_count,
    }
    
    return render(request, 'dashboard/lecturer_dashboard.html', context)

# 🔹 STUDENT DASHBOARD
def student_dashboard(request):
    username = request.session.get('username') or request.GET.get('username', 'Unknown')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')
    
    total_courses = 0
    enrolled_courses = []
    student_id = None
    overall_attendance_rate = 0
    low_attendance_alerts = [] 
    
    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-L8AJQU8\SQLEXPRESS;'
            'DATABASE=Attendance1;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        
        # Fetch student_id, first_name, and last_name
        cursor.execute("""
            SELECT s.StudentID, u.FirstName, u.LastName
            FROM dbo.Students s
            JOIN dbo.Users u ON s.UserID = u.UserID
            WHERE u.Username = ?
        """, (username,))
        student_data = cursor.fetchone()
        
        if not student_data:
            messages.error(request, "Student not found for this username.")
            return redirect('login')
        
        student_id, first_name, last_name = student_data
        
        from datetime import datetime
        today = datetime.now().strftime('%Y-%m-%d')
        session_key = f'alerts_generated_{student_id}_{today}'
        
        if not request.session.get(session_key, False):
            try:
                print(f"🔄 Generating alerts for student {student_id} (first time today)")
                # Call PHP alert generator API
                alert_response = requests.get(
                    f'http://localhost/php_module/alert_generator.php?action=generate&threshold=75',
                    timeout=5
                )
                if alert_response.status_code == 200:
                    alert_data = alert_response.json()
                    if alert_data.get('success'):
                        print(f"✅ Alert generation: Created={alert_data.get('created', 0)}, "
                              f"Skipped (read)={alert_data.get('skipped_read', 0)}, "
                              f"Skipped (recent)={alert_data.get('skipped_recent', 0)}")
                        
                        request.session[session_key] = True
                    else:
                        print(f"❌ Alert generation failed: {alert_data.get('message')}")
                else:
                    print(f"❌ Alert generation failed with status: {alert_response.status_code}")
            except Exception as e:
                print(f"❌ Error calling alert generator: {e}")
                
        else:
            print(f"ℹ️  Alerts already generated today for student {student_id} - skipping")
        
        # ✅ Fetch ONLY UNREAD alerts for student
        cursor.execute("""
            SELECT 
                a.AlertID,
                a.AlertType,
                a.Message,
                a.CreatedDate,
                c.CourseName,
                c.CourseCode
            FROM dbo.Alerts a
            LEFT JOIN dbo.Courses c ON a.CourseID = c.CourseID
            WHERE a.StudentID = ? 
            AND a.IsRead = 0
            ORDER BY a.CreatedDate DESC
        """, (student_id,))
        
        for row in cursor.fetchall():
            low_attendance_alerts.append({
                'alert_id': row.AlertID,
                'alert_type': row.AlertType,
                'message': row.Message,
                'created_date': row.CreatedDate,
                'course_name': row.CourseName,
                'course_code': row.CourseCode
            })
        
        print(f"📋 Found {len(low_attendance_alerts)} UNREAD alerts for student {student_id}")
        
        # Check if there are any read alerts
        cursor.execute("""
            SELECT COUNT(*) as ReadCount
            FROM dbo.Alerts
            WHERE StudentID = ? AND IsRead = 1
        """, (student_id,))
        read_count = cursor.fetchone()[0]
        print(f"📖 Student {student_id} has {read_count} READ alerts in database")
        
        # Fetch enrolled courses
        cursor.execute("""
            SELECT c.CourseID, c.CourseCode, c.CourseName, e.EnrollmentDate, e.Status, e.RejectionReason, e.DropRejectionReason
            FROM dbo.Enrollments e
            JOIN dbo.Courses c ON e.CourseID = c.CourseID
            WHERE e.StudentID = ?
            ORDER BY e.EnrollmentDate DESC
        """, (student_id,))
        enrolled_courses = [
            {
                'CourseID': row.CourseID,
                'CourseCode': row.CourseCode,
                'CourseName': row.CourseName,
                'EnrollmentDate': row.EnrollmentDate,
                'Status': row.Status,
                'RejectionReason': row.RejectionReason,
                'DropRejectionReason': row.DropRejectionReason
            }
            for row in cursor.fetchall()
        ]
        total_courses = len(enrolled_courses)
        
        conn.close()
    except Exception as e:
        messages.warning(request, f"Student stats error: {str(e)} - Using defaults.")
        print(f"❌ Student dashboard error: {e}")
    
    # Get detailed attendance data by calling the PHP API
    active_course_percentages = []
    
    if student_id:
        # Attendance for each course
        for course in enrolled_courses:
            if course['Status'] == 'Active':
                try:
                    resp = requests.get(
                        f'http://localhost/php_module/analytics.php?action=percentage&student_id={student_id}&course_id={course["CourseID"]}', 
                        timeout=5
                    )
                    if resp.status_code == 200:
                        percentage = resp.json().get('percentage', 0)
                        course['percentage'] = percentage
                        active_course_percentages.append(percentage)
                    else:
                        course['percentage'] = 0
                except Exception as e:
                    print(f"Error fetching attendance for course {course['CourseID']}: {e}")
                    course['percentage'] = 0
            else:
                course['percentage'] = 0
        
        # Calculate overall attendance rate
        if active_course_percentages:
            overall_attendance_rate = sum(active_course_percentages) / len(active_course_percentages)
        else:
            overall_attendance_rate = 0
    
    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'student_id': student_id,  
        'dashboard_title': f"<i class='fas fa-tachometer-alt me-2'></i>Student Dashboard - Welcome, {first_name} {last_name}!",
        'total_courses': total_courses,
        'overall_attendance_rate': overall_attendance_rate,
        'enrolled_courses': enrolled_courses,
        'low_attendance_alerts': low_attendance_alerts,
    }
    
    return render(request, 'dashboard/student_dashboard.html', context)


# 🔹 LOGOUT VIEW 
def logout_view(request):
    request.session.flush()
    messages.success(request, "Logged out successfully.")
    return redirect('login')

# ✨ Report page view 
def reports_view(request):
    if 'username' not in request.session:
        return redirect('login')
    
    username = request.session.get('username')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')
    
    period = request.GET.get('period', 'monthly')
    start = request.GET.get('start', '')
    end = request.GET.get('end', '')
    course_id = request.GET.get('course_id', '')
    student_id = request.GET.get('student_id', '')
    student_number = request.GET.get('student_number', '') 
    
    if not start:
        from datetime import datetime
        if period == 'monthly':            
            start = datetime.now().strftime('%Y-%m')
        elif period == 'weekly':
            start = datetime.now().strftime('%Y-%m-%d')
        else: 
            start = datetime.now().strftime('%Y-%m-%d')
    
    if period == 'daily' and not end:
        from datetime import datetime
        end = datetime.now().strftime('%Y-%m-%d')
    
    available_courses = []
    lecturer_id = None
    
    if role == 'lecturer':
        try:
            conn = pyodbc.connect(
                'DRIVER={ODBC Driver 17 for SQL Server};'
                'SERVER=DESKTOP-L8AJQU8\SQLEXPRESS;'
                'DATABASE=Attendance1;'
                'Trusted_Connection=yes;'
            )
            cursor = conn.cursor()
            cursor.execute("""
                SELECT l.LecturerID 
                FROM dbo.Lecturers l 
                JOIN dbo.Users u ON l.UserID = u.UserID 
                WHERE u.Username = ?
            """, (username,))
            row = cursor.fetchone()
            if row:
                lecturer_id = row[0]
            conn.close()
        except Exception as e:
            print(f"Error fetching lecturer ID: {e}")
    
    if role == 'student' and not student_id:
        try:
            conn = pyodbc.connect(
                'DRIVER={ODBC Driver 17 for SQL Server};'
                'SERVER=DESKTOP-L8AJQU8\SQLEXPRESS;'
                'DATABASE=Attendance1;'
                'Trusted_Connection=yes;'
            )
            cursor = conn.cursor()
            cursor.execute("""
                SELECT s.StudentID 
                FROM dbo.Students s 
                JOIN dbo.Users u ON s.UserID = u.UserID 
                WHERE u.Username = ?
            """, (username,))
            row = cursor.fetchone()
            if row:
                student_id = row[0]
            conn.close()
        except Exception as e:
            print(f"Error fetching student ID: {e}")
    
    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-L8AJQU8\SQLEXPRESS;'
            'DATABASE=Attendance1;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        
        if role == 'student' and student_id:
            cursor.execute("""
                SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName
                FROM dbo.Courses c
                JOIN dbo.Enrollments e ON c.CourseID = e.CourseID
                WHERE e.StudentID = ? AND e.Status = 'Active'
                ORDER BY c.CourseCode
            """, (student_id,))
        elif role == 'lecturer' and lecturer_id:
            cursor.execute("""
                SELECT DISTINCT c.CourseID, c.CourseCode, c.CourseName
                FROM dbo.Courses c
                JOIN dbo.Course_Assignments ca ON c.CourseID = ca.CourseID
                WHERE ca.LecturerID = ? AND ca.IsActive = 1
                ORDER BY c.CourseCode
            """, (lecturer_id,))
        else:
            cursor.execute("""
                SELECT CourseID, CourseCode, CourseName
                FROM dbo.Courses
                WHERE IsActive = 1
                ORDER BY CourseCode
            """)
        
        available_courses = [
            {
                'CourseID': row.CourseID,
                'CourseCode': row.CourseCode,
                'CourseName': row.CourseName
            }
            for row in cursor.fetchall()
        ]
        conn.close()
    except Exception as e:
        print(f"Error fetching courses: {e}")
    
    # Build the API URL
    report_data = {'records': [], 'summary': {'total_records': 0, 'avg_percentage': 0}}
    try:
        url = f'http://localhost/php_module/reports.php?period={period}'
        if start:
            url += f'&start={start}'
        if end:
            url += f'&end={end}'
        if course_id:
            url += f'&course_id={course_id}'
        if student_id:
            url += f'&student_id={student_id}'
        if student_number:  
            url += f'&student_number={student_number}'
        if lecturer_id and role == 'lecturer':
            url += f'&lecturer_id={lecturer_id}'
        
        response = requests.get(url, timeout=10)
        if response.status_code == 200:
            report_data = response.json()
    except Exception as e:
        print(f"Error fetching report data: {e}")
    
    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'report_data': report_data,
        'period': period,
        'start': start,
        'end': end,
        'course_id': course_id,
        'student_id': student_id,
        'student_number': student_number,
        'available_courses': available_courses,
        'lecturer_id': lecturer_id,
    }
    
    return render(request, 'dashboard/reports.html', context)
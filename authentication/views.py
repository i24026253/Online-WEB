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
                'SERVER=DESKTOP-VCVQLEJ,1434;'
                'DATABASE=AttendanceManagementDB;'
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

                # ⚠️ Adjust this if you are using hashed passwords
                if password == password_db:
                    # Save session
                    request.session['username'] = username_db
                    request.session['role'] = role_db.lower()
                    request.session['first_name'] = first_name
                    request.session['last_name'] = last_name

                    # Redirect by role
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

# 🔹 ADMIN DASHBOARD
def admin_dashboard(request):
    username = request.session.get('username', 'Unknown')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')

    total_users = 0
    total_students = 0
    total_lecturers = 0
    total_courses = 0

    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-VCVQLEJ,1434;'
            'DATABASE=AttendanceManagementDB;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM dbo.Users")
        total_users = cursor.fetchone()[0]
        cursor.execute("SELECT COUNT(*) FROM dbo.Students")
        total_students = cursor.fetchone()[0]
        cursor.execute("SELECT COUNT(*) FROM dbo.Lecturers")
        total_lecturers = cursor.fetchone()[0]
        cursor.execute("SELECT COUNT(*) FROM dbo.Courses")
        total_courses = cursor.fetchone()[0]
        conn.close()
    except Exception as e:
        messages.warning(request, f"Stats fetch error: {e} - Using defaults.")

    # Call the PHP API to retrieve system statistics and alerts
    system_avg_attendance = 0
    low_alerts = []
    try:
        # Average attendance rate
        response = requests.get('http://localhost:8080/php_module/analytics.php?action=overall_stats', timeout=5)
        if response.status_code == 200:
            data = response.json()
            system_avg_attendance = round(data.get('system_avg_attendance', 0), 2)


        # Low Attendance Alert
        alerts_response = requests.get('http://localhost:8080/php_module/analytics.php?action=alerts&threshold=75', timeout=5)
        if alerts_response.status_code == 200:
            low_alerts = alerts_response.json()
    except Exception as e:
        print(f"Error fetching admin stats: {e}")

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
        'system_avg_attendance': system_avg_attendance,
        'low_alerts': low_alerts,
    }
    return render(request, 'dashboard/admin_dashboard.html', context)

# 🔹 LECTURER DASHBOARD
def lecturer_dashboard(request):
    username = request.session.get('username', 'Unknown')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')

    my_courses_count = 0
    total_students = 0
    sessions_this_week = 0

    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-VCVQLEJ,1434;'
            'DATABASE=AttendanceManagementDB;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        
        # Get LecturerID
        cursor.execute("SELECT LecturerID FROM dbo.Lecturers l JOIN dbo.Users u ON l.UserID = u.UserID WHERE u.Username = ?", (username,))
        lecturer_row = cursor.fetchone()
        lecturer_id = lecturer_row[0] if lecturer_row else None
        
        if lecturer_id:
            # Number of courses taught by the instructor
            cursor.execute("SELECT COUNT(*) FROM dbo.Course_Assignments WHERE LecturerID = ? AND IsActive = 1", (lecturer_id,))
            my_courses_count = cursor.fetchone()[0]
            
            # Total number of registered students
            cursor.execute("""
                SELECT COUNT(DISTINCT e.StudentID) 
                FROM dbo.Enrollments e
                JOIN dbo.Course_Assignments ca ON e.CourseID = ca.CourseID
                WHERE ca.LecturerID = ? AND ca.IsActive = 1 AND e.Status = 'Active'
            """, (lecturer_id,))
            total_students = cursor.fetchone()[0]
            
            # Number of classes this week
            from datetime import datetime, timedelta
            week_start = datetime.now() - timedelta(days=datetime.now().weekday())
            cursor.execute("""
                SELECT COUNT(*) FROM dbo.Attendance_Sessions 
                WHERE LecturerID = ? AND SessionDate >= ?
            """, (lecturer_id, week_start))
            sessions_this_week = cursor.fetchone()[0]
        
        conn.close()
    except Exception as e:
        messages.warning(request, f"Lecturer stats error: {e} - Using defaults.")

    # Call the PHP API to retrieve course statistics
    avg_attendance = 0
    enrolled_students = 0
    
    # Get the instructor's first course ID (for demonstration)）
    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-VCVQLEJ,1434;'
            'DATABASE=AttendanceManagementDB;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        cursor.execute("""
            SELECT TOP 1 ca.CourseID 
            FROM dbo.Course_Assignments ca
            JOIN dbo.Lecturers l ON ca.LecturerID = l.LecturerID
            JOIN dbo.Users u ON l.UserID = u.UserID
            WHERE u.Username = ? AND ca.IsActive = 1
        """, (username,))
        course_row = cursor.fetchone()
        course_id = course_row[0] if course_row else None
        conn.close()
        
        if course_id:
            response = requests.get(f'http://localhost:8080/php_module/analytics.php?action=course_stats&course_id={course_id}', timeout=5)
            if response.status_code == 200:
                data = response.json()
                avg_attendance = round(data.get('avg_attendance', 0), 2)
                enrolled_students = data.get('enrolled_students', 0)
    except Exception as e:
        print(f"Error fetching lecturer stats: {e}")

    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'dashboard_title': f"<i class='fas fa-tachometer-alt me-2'></i>Lecturer Dashboard - Welcome, {first_name} {last_name}!",
        'my_courses_count': my_courses_count,
        'total_students': total_students,
        'sessions_this_week': sessions_this_week,
        'avg_attendance': avg_attendance,
        'enrolled_students': enrolled_students,
    }
    return render(request, 'dashboard/lecturer_dashboard.html', context)


# 🔹 STUDENT DASHBOARD 
def student_dashboard(request):
    username = request.session.get('username') or request.GET.get('username', 'Unknown')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')

    total_courses = 0
    average_attendance = 0
    classes_attended = 0
    enrolled_courses = []
    student_id = None

    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=DESKTOP-VCVQLEJ,1434;'
            'DATABASE=AttendanceManagementDB;'
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

        # Fetch enrolled courses
        cursor.execute("""
            SELECT c.CourseID, c.CourseCode, c.CourseName, e.EnrollmentDate, e.Status
            FROM dbo.Enrollments e
            JOIN dbo.Courses c ON e.CourseID = c.CourseID
            JOIN dbo.Students s ON e.StudentID = s.StudentID
            JOIN dbo.Users u ON s.UserID = u.UserID
            WHERE u.Username = ?
        """, (username,))
        enrolled_courses = [
            {
                'CourseID': row.CourseID,
                'CourseCode': row.CourseCode,
                'CourseName': row.CourseName,
                'EnrollmentDate': row.EnrollmentDate,
                'Status': row.Status
            }
            for row in cursor.fetchall()
        ]

        total_courses = len(enrolled_courses)

        # Fetch attendance statistics
        cursor.execute("""
            SELECT 
                COUNT(*) as total_classes,
                SUM(CASE WHEN ar.Status = 'Present' THEN 1 ELSE 0 END) as classes_attended
            FROM dbo.Attendance_Records ar
            JOIN dbo.Attendance_Sessions s ON ar.SessionID = s.SessionID
            JOIN dbo.Enrollments e ON s.CourseID = e.CourseID
            JOIN dbo.Courses c ON e.CourseID = c.CourseID
            WHERE e.StudentID = ? 
            AND c.AcademicYearID = (SELECT TOP 1 AcademicYearID FROM dbo.Academic_Years WHERE IsActive = 1)
            AND s.IsActive = 1
        """, (student_id,))
        attendance_data = cursor.fetchone()
        total_classes = attendance_data[0] if attendance_data else 0
        classes_attended = attendance_data[1] if attendance_data else 0
        average_attendance = (classes_attended / total_classes * 100) if total_classes > 0 else 0

        conn.close()
    except Exception as e:
        messages.warning(request, f"Student stats error: {str(e)} - Using defaults.")

    # ✨ Get detailed attendance data by calling the PHP API
    attendance_percentage = 0
    if student_id:
        try:
            response = requests.get(f'http://localhost:8080/php_module/analytics.php?action=percentage&student_id={student_id}', timeout=5)
            if response.status_code == 200:
                data = response.json()
                attendance_percentage = data.get('percentage', 0)
            
            # Attendance for each course
            for course in enrolled_courses:
                if course['Status'] == 'Active':
                    try:
                        resp = requests.get(f'http://localhost:8080/php_module/analytics.php?action=percentage&student_id={student_id}&course_id={course["CourseID"]}', timeout=5)
                        if resp.status_code == 200:
                            course['percentage'] = resp.json().get('percentage', 0)
                    except:
                        course['percentage'] = 0
        except Exception as e:
            print(f"Error fetching attendance data: {e}")

    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'dashboard_title': f"<i class='fas fa-tachometer-alt me-2'></i>Student Dashboard - Welcome, {first_name} {last_name}!",
        'total_courses': total_courses,
        'average_attendance': round(average_attendance, 2),
        'classes_attended': classes_attended,
        'total_classes': total_classes,
        'enrolled_courses': enrolled_courses,
        'attendance_percentage': attendance_percentage,
    }
    return render(request, 'dashboard/student_dashboard.html', context)


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
    
    # If it is a student, the student_id will be set automatically
    if role == 'student' and not student_id:
        try:
            conn = pyodbc.connect(
                'DRIVER={ODBC Driver 17 for SQL Server};'
                'SERVER=DESKTOP-VCVQLEJ,1434;'
                'DATABASE=AttendanceManagementDB;'
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
    
    report_data = []
    try:
        url = f'http://localhost:8080/php_module/reports.php?period={period}'
        if start:
            url += f'&start={start}'
        if end:
            url += f'&end={end}'
        if course_id:
            url += f'&course_id={course_id}'
        if student_id:
            url += f'&student_id={student_id}'
        
        response = requests.get(url, timeout=10)
        if response.status_code == 200:
            report_data = response.json()
    except Exception as e:
        print(f"Error fetching report data: {e}")
        report_data = {'summary': {'total_records': 0, 'avg_percentage': 0}}
    
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
    }
    
    return render(request, 'dashboard/reports.html', context)





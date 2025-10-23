import pyodbc
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
                'SERVER=LAPTOP-8O7OUMB4;'
                'DATABASE=WebGrpData;'
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
            'SERVER=LAPTOP-8O7OUMB4;'
            'DATABASE=WebGrpData;'
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
            'SERVER=LAPTOP-8O7OUMB4;'
            'DATABASE=WebGrpData;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM dbo.Courses WHERE LecturerUsername = ?", (username,))
        my_courses_count = cursor.fetchone()[0]
        cursor.execute("SELECT COUNT(*) FROM dbo.Students WHERE CourseID IN (SELECT ID FROM dbo.Courses WHERE LecturerUsername = ?)", (username,))
        total_students = cursor.fetchone()[0]
        from datetime import datetime, timedelta
        week_start = datetime.now() - timedelta(days=datetime.now().weekday())
        cursor.execute("SELECT COUNT(*) FROM dbo.Sessions WHERE LecturerUsername = ? AND Date >= ?", (username, week_start))
        sessions_this_week = cursor.fetchone()[0]
        conn.close()
    except Exception as e:
        messages.warning(request, f"Lecturer stats error: {e} - Using defaults.")

    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'dashboard_title': f"<i class='fas fa-tachometer-alt me-2'></i>Lecturer Dashboard - Welcome, {first_name} {last_name}!",
        'my_courses_count': my_courses_count,
        'total_students': total_students,
        'sessions_this_week': sessions_this_week,
    }
    return render(request, 'dashboard/lecturer_dashboard.html', context)

# 🔹 STUDENT DASHBOARD
def student_dashboard(request):
    username = request.session.get('username', 'Unknown')
    role = request.session.get('role', 'unknown')
    first_name = request.session.get('first_name', '')
    last_name = request.session.get('last_name', '')

    total_courses = 0
    average_attendance = 0
    classes_attended = 0

    try:
        conn = pyodbc.connect(
            'DRIVER={ODBC Driver 17 for SQL Server};'
            'SERVER=LAPTOP-8O7OUMB4;'
            'DATABASE=WebGrpData;'
            'Trusted_Connection=yes;'
        )
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM dbo.StudentCourses WHERE StudentUsername = ?", (username,))
        total_courses = cursor.fetchone()[0]
        cursor.execute("SELECT AVG(AttendanceRate) FROM dbo.Students WHERE Username = ?", (username,))
        average_attendance = cursor.fetchone()[0] or 0
        cursor.execute("SELECT COUNT(*) FROM dbo.AttendanceRecords WHERE StudentUsername = ? AND Status = 'Present'", (username,))
        classes_attended = cursor.fetchone()[0]
        conn.close()
    except Exception as e:
        messages.warning(request, f"Student stats error: {e} - Using defaults.")

    context = {
        'user_role': role,
        'username': username,
        'first_name': first_name,
        'last_name': last_name,
        'dashboard_title': f"<i class='fas fa-tachometer-alt me-2'></i>Student Dashboard - Welcome, {first_name} {last_name}!",
        'total_courses': total_courses,
        'average_attendance': average_attendance,
        'classes_attended': classes_attended,
    }
    return render(request, 'dashboard/student_dashboard.html', context)

import pyodbc
# Connection string based on your Django settings
# Connection string based on your Django settings
conn_str = (
    r'DRIVER={ODBC Driver 17 for SQL Server};'
    r'SERVER=HOH\SQLEXPRESS;'
    r'DATABASE=AttendanceManagementDB;'
    r'Trusted_Connection=yes;'
)

try:
    conn = pyodbc.connect(conn_str)
    print("🚀 Connection successful!")
    
    cursor = conn.cursor()
    cursor.execute("SELECT DB_NAME()")  # Test query
    db_name = cursor.fetchone()[0]
    print(f"Current database: {db_name}")
    
    conn.close()
except Exception as e:
    print(f"❌ Data operation failed: {e}")
    print("❌ Please check your database configuration!")
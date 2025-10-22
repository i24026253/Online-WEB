"""
authentication/models.py
Models that map to your existing MySQL database
"""

from django.db import models
from django.contrib.auth.models import User

class Student(models.Model):
    """
    Maps to existing Students table in MySQL
    """
    student_id = models.AutoField(db_column='StudentID', primary_key=True)
    user_id = models.IntegerField(db_column='UserID')
    student_number = models.CharField(db_column='StudentNumber', max_length=50)
    date_of_birth = models.DateField(db_column='DateOfBirth', null=True, blank=True)
    gender = models.CharField(db_column='Gender', max_length=10, null=True, blank=True)
    address = models.TextField(db_column='Address', null=True, blank=True)
    city = models.CharField(db_column='City', max_length=100, null=True, blank=True)
    state = models.CharField(db_column='State', max_length=100, null=True, blank=True)
    zip_code = models.CharField(db_column='ZipCode', max_length=20, null=True, blank=True)
    country = models.CharField(db_column='Country', max_length=100, null=True, blank=True)
    emergency_contact = models.CharField(db_column='EmergencyContact', max_length=100, null=True, blank=True)
    emergency_phone = models.CharField(db_column='EmergencyPhone', max_length=20, null=True, blank=True)
    enrollment_date = models.DateField(db_column='EnrollmentDate', null=True, blank=True)
    is_active = models.BooleanField(db_column='IsActive', default=True)
    
    class Meta:
        managed = False  # Don't let Django manage this table
        db_table = 'Students'  # Use existing table name
    
    def __str__(self):
        return f"{self.student_number}"
    
    def get_user(self):
        """Get Django user associated with this student"""
        try:
            return User.objects.get(id=self.user_id)
        except User.DoesNotExist:
            return None


class Lecturer(models.Model):
    """
    Lecturer model (will be created by Django)
    """
    user = models.OneToOneField(User, on_delete=models.CASCADE, related_name='lecturer')
    employee_id = models.CharField(max_length=50, unique=True)
    department = models.CharField(max_length=100)
    is_active = models.BooleanField(default=True)
    created_at = models.DateTimeField(auto_now_add=True)
    
    class Meta:
        db_table = 'Lecturers'
    
    def __str__(self):
        return f"{self.employee_id} - {self.user.get_full_name()}"
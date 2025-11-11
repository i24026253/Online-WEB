"""
attendance_system/urls.py - Main URL Configuration
"""
from django.contrib import admin
from django.urls import path, include
from django.conf import settings
from django.conf.urls.static import static
from authentication import views as auth_views

urlpatterns = [
    path('admin/', admin.site.urls),
    
    # Authentication URLs
    path('', auth_views.login_view, name='login'),
    path('login/', auth_views.login_view, name='login'),
    path('logout/', auth_views.logout_view, name='logout'),
    path('forgot-password/', auth_views.forgot_password_view, name='forgot_password'),
    
    # Dashboard URLs
    path('dashboard/', auth_views.dashboard_view, name='dashboard'),
    path('admin-dashboard/', auth_views.admin_dashboard, name='admin_dashboard'),
    path('lecturer-dashboard/', auth_views.lecturer_dashboard, name='lecturer_dashboard'),
    path('student-dashboard/', auth_views.student_dashboard, name='student_dashboard'),
    path('reports/', auth_views.reports_view, name='reports'),
]

# Serve static and media files in development
if settings.DEBUG:
    urlpatterns += static(settings.STATIC_URL, document_root=settings.STATIC_ROOT)
    urlpatterns += static(settings.MEDIA_URL, document_root=settings.MEDIA_ROOT)
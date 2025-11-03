@echo off
REM run_alert_generator.bat
REM This script generates low attendance alerts automatically
REM Can be scheduled to run daily using Windows Task Scheduler

REM Change to your PHP installation directory
set PHP_PATH=C:\xampp\php\php.exe

REM Change to your php_module directory
set SCRIPT_PATH=C:\path\to\your\php_module\alert_generator.php

REM Log file location
set LOG_PATH=C:\path\to\your\logs\alert_generator.log

echo ========================================= >> %LOG_PATH%
echo Alert Generator Run: %date% %time% >> %LOG_PATH%
echo ========================================= >> %LOG_PATH%

REM Run the PHP script
"%PHP_PATH%" "%SCRIPT_PATH%" >> %LOG_PATH% 2>&1

if %errorlevel% equ 0 (
    echo Success: Alerts generated successfully >> %LOG_PATH%
) else (
    echo Error: Alert generation failed with code %errorlevel% >> %LOG_PATH%
)
 
echo. >> %LOG_PATH%
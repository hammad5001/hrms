@echo off
title Commercial Attendance Collector
cd /d "%~dp0"
if not exist "venv\Scripts\python.exe" (
    echo ERROR: Python venv not found in this folder.
    echo Run: python -m venv venv
    echo Then: venv\Scripts\pip install pyzk pandas pymysql openpyxl
    pause
    exit /b 1
)
call venv\Scripts\activate
set PYTHONIOENCODING=utf-8
python attendance_collector_commercial.py
pause

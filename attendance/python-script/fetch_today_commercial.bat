@echo off
title Fetch Today - Commercial Branch
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
echo ============================================
echo Commercial Collector v3.2.0 - LAN 10.10.40.221 + public 125.209.68.118:4370
echo Same logic as attendance_collector.py - use THIS file only
echo ============================================
python -c "import attendance_collector_commercial as m; print('Version:', m.SCRIPT_VERSION)" 2>nul
if errorlevel 1 (
    echo ERROR: Wrong folder or missing pyzk. Must run from python-script folder.
    pause
    exit /b 1
)
echo.
echo Fetching today's commercial attendance...
python attendance_collector_commercial.py today
echo.
if %ERRORLEVEL% EQU 0 (
    echo SUCCESS - Data fetched and synced.
) else (
    echo FAILED - Check device IP in config_commercial.json and network/VPN.
)
pause

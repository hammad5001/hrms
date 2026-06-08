@echo off
cd /d "%~dp0"
call venv\Scripts\activate
python auto_run_commercial.py
pause

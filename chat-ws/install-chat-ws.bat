@echo off
setlocal
cd /d "%~dp0"

where node >nul 2>&1
if errorlevel 1 (
    echo Node.js is not installed or not on PATH.
    echo.
    echo Install Node.js LTS from: https://nodejs.org/
    echo Or run in PowerShell as admin:
    echo   winget install OpenJS.NodeJS.LTS
    echo.
    echo Then CLOSE and REOPEN this terminal, and run this script again.
    pause
    exit /b 1
)

echo Node: 
node -v
echo npm:
call npm -v
echo.
echo Installing chat WebSocket dependencies...
call npm install
if errorlevel 1 (
    echo npm install failed.
    pause
    exit /b 1
)
echo.
echo Done. Start the server with: npm start
echo Or double-click start-chat-ws.bat
pause

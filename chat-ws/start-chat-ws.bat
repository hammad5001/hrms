@echo off
setlocal
cd /d "%~dp0"

where node >nul 2>&1
if errorlevel 1 (
    echo Node.js not found. Run install-chat-ws.bat after installing Node from https://nodejs.org/
    pause
    exit /b 1
)

if not exist node_modules (
    echo Installing dependencies...
    call npm install
    if errorlevel 1 pause & exit /b 1
)

echo Starting Balitech Chat WebSocket on port 8765...
echo Keep this window open while using chat.
node server.js
pause

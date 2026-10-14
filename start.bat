@echo off
setlocal

echo.
echo ================================
echo SupportAssist Backend Startup
echo ================================
echo.

set "PROJECT_ROOT=%~dp0"
where php >nul 2>nul
if errorlevel 1 (
    echo ERROR: PHP was not found on PATH. Install PHP 8.3+ and add it to PATH.
    pause
    exit /b 1
)

if not exist "%PROJECT_ROOT%.env" (
    echo ERROR: .env file not found.
    echo Copy .env.example to .env and configure local settings first.
    pause
    exit /b 1
)

cd /d "%PROJECT_ROOT%"
echo API URL: http://localhost:8080
echo Press Ctrl+C to stop the server.
php -S localhost:8080 -t public

@echo off
setlocal enabledelayedexpansion

echo.
echo ================================
echo SupportAssist Backend Startup
echo ================================
echo.

REM Set paths
set PHP_PATH=C:\Users\DELL\Downloads\php-8.5.10-nts-Win32-vs17-x64\php.exe
set PROJECT_ROOT=%~dp0

REM Check if PHP exists
if not exist "%PHP_PATH%" (
    echo ERROR: PHP not found at %PHP_PATH%
    echo Please update the PHP_PATH variable in this script.
    pause
    exit /b 1
)

REM Check if .env exists
if not exist "%PROJECT_ROOT%.env" (
    echo ERROR: .env file not found
    echo Run: copy .env.example .env
    pause
    exit /b 1
)

REM Set PostgreSQL password
set PGPASSWORD=tanaka

echo [OK] PHP: %PHP_PATH%
echo [OK] Project: %PROJECT_ROOT%
echo [OK] PostgreSQL: Connected as postgres
echo.
echo Starting development server...
echo.
echo API URL: http://0.0.0.0:8080/api
echo Default login: admin@helpdesk.com / admin123
echo.
echo Press Ctrl+C to stop the server
echo.

cd /d "%PROJECT_ROOT%"
"%PHP_PATH%" -S 0.0.0.0:8080 -t public

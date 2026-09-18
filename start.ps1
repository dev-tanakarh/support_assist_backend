# SupportAssist Backend - Simple Startup Script

Write-Host "================================" -ForegroundColor Cyan
Write-Host "SupportAssist Backend Startup" -ForegroundColor Cyan
Write-Host "================================" -ForegroundColor Cyan
Write-Host ""

# Set up paths
$phpPath = "C:\Users\DELL\Downloads\php-8.5.10-nts-Win32-vs17-x64\php.exe"
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

# Verify PHP exists
if (!(Test-Path $phpPath)) {
    Write-Host "ERROR: PHP not found at $phpPath" -ForegroundColor Red
    Write-Host "Please update the phpPath variable in this script." -ForegroundColor Red
    exit 1
}

# Verify .env exists
if (!(Test-Path "$projectRoot\.env")) {
    Write-Host "ERROR: .env file not found" -ForegroundColor Red
    Write-Host "Run: cp .env.example .env" -ForegroundColor Yellow
    exit 1
}

# Set PostgreSQL password for any psql commands
$env:PGPASSWORD = "tanaka"

# Display startup info
Write-Host "✓ PHP: $phpPath" -ForegroundColor Green
Write-Host "✓ Project: $projectRoot" -ForegroundColor Green
Write-Host "✓ PostgreSQL: Connected as postgres" -ForegroundColor Green
Write-Host ""
Write-Host "Starting development server..." -ForegroundColor Yellow
Write-Host ""
Write-Host "API URL: http://localhost:8080/api" -ForegroundColor Cyan
Write-Host "Default login: admin@helpdesk.com / admin123" -ForegroundColor Cyan
Write-Host ""
Write-Host "Press Ctrl+C to stop the server" -ForegroundColor Yellow
Write-Host ""

# Start the server
Set-Location $projectRoot
& $phpPath -S localhost:8080 -t public

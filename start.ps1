# SupportAssist Backend - Local PHP development server

$ErrorActionPreference = "Stop"
$projectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    Write-Error "PHP was not found on PATH. Install PHP 8.3+ and add it to PATH."
    exit 1
}

if (-not (Test-Path (Join-Path $projectRoot ".env"))) {
    Write-Error ".env file not found. Copy .env.example to .env and configure local settings first."
    exit 1
}

Set-Location $projectRoot
Write-Host "API URL: http://localhost:8080" -ForegroundColor Cyan
Write-Host "Press Ctrl+C to stop the server." -ForegroundColor Yellow
php -S localhost:8080 -t public

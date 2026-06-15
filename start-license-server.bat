@echo off
REM GD Workflow Bridge Pro - Start License Server with Database
REM Sets PostgreSQL + Redis environment variables and starts license server

setlocal enabledelayedexpansion

echo.
echo =========================================
echo GD Workflow Bridge Pro - License Server
echo =========================================
echo.

REM Set PostgreSQL connection details
set LICENSE_DB_HOST=127.0.0.1
set LICENSE_DB_PORT=5432
set LICENSE_DB_USER=gdwb_user
set LICENSE_DB_PASS=/FdCDrG6wWczmjJvgXl28w==
set LICENSE_DB_NAME=gdwb_app

REM Set Redis details
set REDIS_HOST=127.0.0.1
set REDIS_PORT=6379

REM Set server config
set LICENSE_SERVER_PORT=8001
set LICENSE_SERVER_HOST=127.0.0.1

echo [*] Environment Variables:
echo     Database: !LICENSE_DB_USER!@!LICENSE_DB_HOST!:!LICENSE_DB_PORT!/!LICENSE_DB_NAME!
echo     Redis: !REDIS_HOST!:!REDIS_PORT!
echo     License Server: !LICENSE_SERVER_HOST!:!LICENSE_SERVER_PORT!
echo.

echo [*] Starting License Server with Database...
echo.

cd /d "%~dp0"
php -S !LICENSE_SERVER_HOST!:!LICENSE_SERVER_PORT! -t license-server license-server/index.php

pause

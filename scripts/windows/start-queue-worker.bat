@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "PROJECT_DIR=%~dp0..\.."
for %%I in ("%PROJECT_DIR%") do set "PROJECT_DIR=%%~fI"

if not exist "%PROJECT_DIR%\artisan" (
  echo [queue-worker] ERROR: artisan not found in "%PROJECT_DIR%"
  exit /b 1
)

set "LOG_DIR=%PROJECT_DIR%\storage\logs"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
set "LOG_FILE=%LOG_DIR%\queue-worker.log"

set "PHP_EXE="
for /f "delims=" %%P in ('dir /b /s "C:\laragon\bin\php\php.exe" 2^>nul ^| sort /r') do (
  set "PHP_EXE=%%P"
  goto :php_found
)

if not defined PHP_EXE (
  for /f "delims=" %%P in ('where php 2^>nul') do (
    set "PHP_EXE=%%P"
    goto :php_found
  )
)

:php_found
if not defined PHP_EXE (
  echo [queue-worker] ERROR: php.exe not found >> "%LOG_FILE%"
  exit /b 1
)

echo [queue-worker] Starting worker with "%PHP_EXE%" at %DATE% %TIME%>> "%LOG_FILE%"
cd /d "%PROJECT_DIR%"
"%PHP_EXE%" artisan queue:work --queue=default,media-generation --sleep=3 --tries=3 --timeout=1800 >> "%LOG_FILE%" 2>&1

echo [queue-worker] Worker exited with code %ERRORLEVEL% at %DATE% %TIME%>> "%LOG_FILE%"
exit /b %ERRORLEVEL%

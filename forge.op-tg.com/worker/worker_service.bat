@echo off
TITLE OPT Nexus Worker Service (auto-restart)
setlocal enableextensions enabledelayedexpansion
cd /d "%~dp0"
if not exist logs mkdir logs

REM Ensure Playwright uses bundled browsers
set PLAYWRIGHT_BROWSERS_PATH=%CD%\ms-playwright
chcp 65001 >nul 2>&1

:loop
if exist node\node.exe (
	echo [svc] launching embedded node runtime >> logs\service-out.log
	node\node.exe index.js >> logs\service-out.log 2>&1
) else if exist worker.exe (
	echo [svc] launching worker.exe >> logs\service-out.log
	worker.exe >> logs\service-out.log 2>&1
) else (
	echo [svc] launching system node >> logs\service-out.log
	node index.js >> logs\service-out.log 2>&1
)
echo [svc] Worker exited with %ERRORLEVEL% - restarting in 5s... >> logs\service-out.log
timeout /t 5 /nobreak >nul
goto loop

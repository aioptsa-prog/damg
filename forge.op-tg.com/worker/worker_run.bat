@echo off
TITLE OptForge Worker (Self-contained)
setlocal enableextensions enabledelayedexpansion
cd /d "%~dp0"
if not exist logs mkdir logs

REM Use UTF-8 console to avoid garbled Arabic text
chcp 65001 >nul 2>&1

REM Ensure Playwright uses bundled browsers (offline)
set "PLAYWRIGHT_BROWSERS_PATH=%CD%\ms-playwright"

REM Preferred: embedded portable Node (most reliable)
set "NODE_BIN=%CD%\node\node.exe"
if exist "%NODE_BIN%" (
    echo Launching with embedded Node ...
    start "" cmd /c start http://127.0.0.1:4499/status
    "%NODE_BIN%" index.js
    goto :end
)

REM Fallback: packaged worker.exe (pkg) — used only if embedded node missing
if exist worker.exe (
    echo Launching worker.exe (pkg) ...
    start "" cmd /c start http://127.0.0.1:4499/status
    worker.exe
    goto :end
)

REM Last resort: system Node (requires Node installed on the machine)
echo Launching with system Node ...
start "" cmd /c start http://127.0.0.1:4499/status
node index.js

:end
echo.
echo تم إنهاء العملية. اضغط أي مفتاح للاستمرار...
PAUSE

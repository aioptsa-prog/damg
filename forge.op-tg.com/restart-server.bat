@echo off
echo Restarting PHP Server...
echo.

REM Find and kill the PHP server process
for /f "tokens=2" %%a in ('netstat -ano ^| findstr ":8080.*LISTENING"') do (
    echo Stopping process on port 8080...
    taskkill /F /PID %%a >nul 2>&1
)

timeout /t 2 >nul

echo Starting PHP server on port 8080...
start "PHP Server" cmd /k "cd /d %~dp0 && php -S localhost:8080 -t . router.php"

echo.
echo PHP server restarted!
echo Access at: http://localhost:8080
echo.
pause

@echo off
REM ================================================
REM LeadHub Production Build Script
REM تجميع ملفات النشر
REM ================================================

echo.
echo ========================================
echo    LeadHub Production Build
echo ========================================
echo.

REM Set variables
set "PROJECT_DIR=%~dp0"
set "RELEASE_DIR=%PROJECT_DIR%releases\production-build"
set "FRONTEND_DIR=%PROJECT_DIR%saudi-lead-iq-main"

REM Clean old build
echo [1/6] Cleaning old build...
if exist "%RELEASE_DIR%" rmdir /s /q "%RELEASE_DIR%"
mkdir "%RELEASE_DIR%"

REM Build Frontend
echo [2/6] Building Frontend (React)...
cd "%FRONTEND_DIR%"
call npm run build
if errorlevel 1 (
    echo ERROR: Frontend build failed!
    pause
    exit /b 1
)

REM Copy Frontend
echo [3/6] Copying Frontend files...
mkdir "%RELEASE_DIR%\app"
xcopy "%FRONTEND_DIR%\dist\*" "%RELEASE_DIR%\app\" /e /i /q

REM Copy Backend
echo [4/6] Copying Backend files...
xcopy "%PROJECT_DIR%v1" "%RELEASE_DIR%\v1\" /e /i /q
xcopy "%PROJECT_DIR%lib" "%RELEASE_DIR%\lib\" /e /i /q
xcopy "%PROJECT_DIR%config" "%RELEASE_DIR%\config\" /e /i /q
mkdir "%RELEASE_DIR%\storage"
copy "%PROJECT_DIR%storage\database.sqlite" "%RELEASE_DIR%\storage\" /y

REM Copy configuration files
echo [5/6] Copying configuration files...
copy "%PROJECT_DIR%releases\production.htaccess" "%RELEASE_DIR%\.htaccess" /y
copy "%PROJECT_DIR%releases\production.env.php" "%RELEASE_DIR%\config\.env.php.example" /y
copy "%PROJECT_DIR%DEPLOYMENT_GUIDE.md" "%RELEASE_DIR%\README.md" /y

REM Create index router
echo [6/6] Creating router...
echo ^<?php > "%RELEASE_DIR%\index.php"
echo header('Location: /app/'); >> "%RELEASE_DIR%\index.php"


echo.
echo ========================================
echo    Build Complete!
echo ========================================
echo.
echo Production files are ready at:
echo %RELEASE_DIR%
echo.
echo Next steps:
echo 1. Edit config/.env.php with your domain
echo 2. Upload all files to public_html
echo 3. Set permissions: chmod 755 storage/
echo.

pause

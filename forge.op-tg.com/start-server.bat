@echo off
REM Start PHP Development Server
REM This serves the entire project on http://localhost:8000

echo Starting PHP Development Server...
echo URL: http://localhost:8000
echo.
echo API Endpoints:
echo   - http://localhost:8000/v1/api/auth/login
echo   - http://localhost:8000/v1/api/auth/me
echo   - http://localhost:8000/v1/api/leads/index.php
echo   - http://localhost:8000/v1/api/categories/index.php
echo.
echo Press Ctrl+C to stop
echo.

php -S localhost:8000 -t .

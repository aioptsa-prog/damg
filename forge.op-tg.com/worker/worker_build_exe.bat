@echo off
TITLE Build worker.exe via pkg (OPT Nexus)
if not exist logs mkdir logs
echo Installing dependencies...
npm i || goto :err
echo Installing Playwright Chromium...
npx playwright install chromium --with-deps || echo WARN: Playwright install returned non-zero.
echo Building EXE from launcher.js...
npx pkg -t node18-win-x64 launcher.js -o worker.exe || goto :err
echo Done. Output: worker.exe
echo NOTE: Some Playwright binaries may not embed perfectly in EXE. If you face issues, use worker_run.bat (Node.js runtime).
PAUSE
exit /b 0
:err
echo Build failed. Check the output above.
PAUSE
exit /b 1

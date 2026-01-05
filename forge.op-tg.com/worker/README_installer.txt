OPT Nexus Worker — Installer Notes

This installer is fully offline-capable:
- Bundled Playwright browsers under ms-playwright
- Optional worker.exe (if available) or an embedded portable Node runtime under node\node.exe
- No npm or internet required on the target machine

Run options after install:
1) Desktop shortcut (worker_run.bat) — interactive console with logs
2) Start Menu > Install as Service — installs a Windows Service that auto-restarts

Troubleshooting:
- Check {app}\logs\worker.log for errors.
- Ensure BASE_URL and INTERNAL_SECRET are correct in {app}\.env
- If company policy blocks EXE from running, unblock via file Properties > Unblock.
- If service doesn’t start, run PowerShell as Administrator and execute:
  powershell -NoProfile -ExecutionPolicy Bypass -File install_service.ps1

Support:
- Contact your administrator with worker.log and any error messages.

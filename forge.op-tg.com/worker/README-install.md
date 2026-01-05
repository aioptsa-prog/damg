# Windows One-Click Install and Service

This folder contains scripts to package the worker and install it as a Windows service.

Requirements:
- Node.js LTS
- Inno Setup (ISCC) in PATH to build the MSI/EXE installer
- Optional: NSSM in PATH for robust Windows Service hosting

Steps:
1. Build the EXE and installer
   - Open PowerShell in `worker/`
   - Run:
     ```powershell
     ./build_installer.ps1
     ```
   - Output will be in `worker/build/` as `LeadsWorker_Setup.exe`

2. Install
   - Run the generated installer. It places files in `C:\Program Files\LeadsWorker` by default and can auto-launch the app.
   - On first start, open `http://127.0.0.1:4499` to complete the First-Run setup (BASE_URL, INTERNAL_SECRET, WORKER_ID, etc.).

3. Install as a Windows Service (optional)
   - Open an elevated PowerShell window (Run as Administrator)
   - Navigate to the install folder (e.g., `C:\Program Files\LeadsWorker`)
   - Run:
     ```powershell
     # Preferred if you have NSSM
     nssm install LeadsWorker "C:\Program Files\LeadsWorker\worker.exe"
     nssm set LeadsWorker AppDirectory "C:\Program Files\LeadsWorker"
     nssm set LeadsWorker Start SERVICE_AUTO_START
     nssm start LeadsWorker

     # Or use the helper script (tries NSSM, falls back to sc.exe + worker_service.bat)
     powershell -ExecutionPolicy Bypass -File install_service.ps1
     ```

4. Monitor
   - Status UI: http://127.0.0.1:4499
   - Logs: inside `logs/` next to the executable

Notes:
- The worker supports centralized configuration via `WORKER_CONF_URL` and `WORKER_CONF_CODE`. If set, it will pull base URL and intervals from the server.
- The First-Run page writes to `.env` in the working directory, and then starts the worker loop.

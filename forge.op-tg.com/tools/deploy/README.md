# Deployment (shared hosting)

This folder contains a PowerShell deploy script that syncs your workspace to a timestamped release on your shared host and performs a near-atomic swap to `current` with optional maintenance mode.

Prerequisites
- Windows host with PowerShell 5.1+
- WinSCP installed and `WinSCP.com` available on PATH (https://winscp.net/)
- SFTP access to your server (adjust host, user, and base path)

Scripts
- `deploy.ps1` — Syncs to `/releases/<yyyyMMdd_HHmmss>`, renames old `current` to `prev_<ts>`, and promotes the new release to `current`.
  - Excludes logs, VCS, node_modules, Playwright browser binaries, etc.
  - Optional maintenance flag toggling before/after swap.
  - Keeps last 3 releases (auto-prunes older ones).
  - `-Rollback` switch: promotes latest `prev_*` back to `current` and stashes current as `prev_rollback_<ts>`.
- `release_and_deploy.ps1` — Runs build zip (make_release.ps1), then deploy.ps1, then updates `storage/releases/latest.json`.
- `deploy_cpanel_uapi.ps1` — Fallback deploy via cPanel UAPI (upload zip, extract to releases/<ts>, swap current). Use if SFTP is blocked.

Parameters
- `LocalPath` (default `D:\LeadsMembershipPRO`) — Local workspace root to upload
- `RemoteHost` — SFTP host
- `Port` (default `22`) — SFTP port
- `User` — SSH username
- `Password` — SecureString. Use `Read-Host -AsSecureString` or a secure secret store
- `RemoteBase` — Base directory on the server (e.g., `/home/USER/your-site`)
- `-DryRun` — Show WinSCP script without executing
- `-Maintenance` — Create `maintenance.flag` before swap and remove after

Usage notes
- Password handling: the script accepts a SecureString and passes it to WinSCP using `-rawsettings PasswordPlain=...`. Prefer SSH keys for production, or configure a saved WinSCP session and adjust the script to `open "session-name"`.
- Host keys: for first-time connections, consider setting `-hostkey=*` or the specific fingerprint to avoid prompts. You can add extra WinSCP options in the `open` line if needed.
- Rollback: use `deploy.ps1 -Rollback` to swap back to the latest `prev_*`. You can also roll back manually by renaming dirs over SFTP/SSH if needed.
- Maintenance page: the server should be configured to serve `maintenance.html` when `maintenance.flag` exists (already added in .htaccess).

Troubleshooting
- If you see auth prompts, ensure `WinSCP.com` finds the credentials; check the `open` line and that your password or SSH key is valid.
- If pruning fails, your shell might not support `bash -lc`. Replace the cleanup command with a portable alternative for your host.
- If `current` doesn’t exist yet on first deploy, the script continues (it prints `no current`).

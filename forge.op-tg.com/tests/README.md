# Test and QA Guide

This folder contains a minimal, automated smoke test that validates core features end-to-end without external dependencies.

What it covers:
- Global stop and daily pause enforcement in internal pull endpoint
- Job picking strategy (job_pick_order: fifo, newest, random)
- Leasing/expired lease handling basics
- Classification basics, enabled/disabled rules, and threshold
- Export CSV behavior: UTF-8 BOM, sep=, hint, role scoping, and export_max_rows truncation note

How to run (Windows PowerShell):

```
php -d display_errors=1 -d error_reporting=E_ALL tests/smoke.php
```

Expected result: the script exits normally and prints a single line like:

```
All smoke tests passed at 2025-09-30 17:10:00
```

If any assertion fails, the script throws with "ASSERT FAIL: ..." so you can quickly locate the failing area.

Notes
- The smoke test defines UNIT_TEST to avoid download headers/exit when including exporter endpoints.
- The test sets and resets key settings, and uses a temporary admin user if needed.
- It avoids external HTTP calls and does not invoke the real browser worker.

Manual scenarios (recommended)
- Worker e2e: enable internal, set secret, start worker, and queue a job using `api/dev_add_job_noauth.php` from localhost; watch worker logs in `worker/logs`.
- Pause window: set pause to cover now and observe worker pauses (heartbeat returns stopped: true) and pull_job returns stopped.
- Exports: in admin UI, try CSV/XLS/XLSX with and without filters; confirm Arabic/RTL display in Excel and that phone numbers stay textual.
- Classification UI: toggle rules enabled/disabled and verify the quick reclassify updates categories; try bulk enable/disable and import/export taxonomy.

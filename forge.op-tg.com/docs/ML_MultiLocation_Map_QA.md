# Multi‑Location Map UI — QA Checklist

This guide helps verify the new multi‑location pin‑based map experience in `admin/fetch.php` using Leaflet.

## Preconditions
- Admin logged in.
- Server is running (e.g., `serve-php-8080`).
- Leaflet assets load (local or CDN fallbacks).

## Quick Tour
1. Open Admin → Fetch page (`admin/fetch.php`).
2. Toggle "تفعيل وضع متعدد المواقع".
3. A new control bar appears above the map with:
   - "إضافة دبوس" (Add Pin)
   - "حذف المحدد" (Remove Selected)
   - Live counter: "عدد المواقع المضافة: N من MAX".

## Core Behaviors

### A) Add pins and bind to rows
- Click "إضافة دبوس" twice.
- Expected:
  - Two pins appear and are auto‑selected one at a time.
  - Two rows appear in the list with LL/Radius/City reflecting each pin.
  - The map auto‑fits to show both pins.

### B) Drag pin → updates fields
- Drag the first pin a bit.
- Expected:
  - Corresponding row LL updates instantly to the new coordinates.
  - The blue circle follows the pin.
  - Popup (hover/click) shows the updated LL.

### C) Edit fields → moves pin
- Edit the LL field of the second row (e.g., set lat/lng to a nearby location).
- Edit its Radius (e.g., from 25 to 15).
- Expected:
  - Pin moves immediately to the new LL.
  - Circle radius updates accordingly.
  - Map re‑fits on change.

### D) Selection highlight
- Click the second pin.
- Expected:
  - Its row gets highlighted.
  - A popup shows City/Radius and Edit/Delete buttons.
- Click the row card.
- Expected:
  - The corresponding pin becomes active and opens its popup.

### E) Delete pin
- In popup, click Delete; or click the row’s Delete button.
- Expected:
  - Both the pin and its row are removed.
  - The counter decrements.
  - Map re‑fits to remaining pins.

### F) Limits and counter
- Add pins until reaching MAX (Settings: `MAX_MULTI_LOCATIONS`).
- Expected:
  - Counter shows N of MAX.
  - N+1 attempt politely refuses (alert or message), no new pin added.

### G) Create group (jobs_multi_create)
- Ensure a valid category and base query are set.
- Click "إنشاء مهام متعددة".
- Expected:
  - Success badge with group ID and total jobs created.
  - Details collapsible shows JSON with per‑location results.
  - Location order in payload matches row order (top to bottom).

## Map‑level Controls
- "إعادة ضبط الإطار":
  - With multi‑location enabled: fits all pins/circles.
  - With multi‑location disabled: fits the single circle.

## Single vs Multi Modes
- Multi‑location OFF:
  - Map click sets single LL; one draggable marker + circle visible.
- Multi‑location ON:
  - Single marker/circle are hidden.
  - Map click does not add a pin (use the Add Pin button instead).

## Mobile Checks (≤ 640px width)
- Map maintains ~300px height; UI elements don’t overlap.
- Controls wrap gracefully; no content is cut off.
- Dragging pins is possible, but if uncomfortable, use the fields to adjust LL/Radius.

## Performance
- Repeat A–E with 2, 5, then 10 pins:
  - No noticeable lag on drag or edits.
  - Popups open quickly.
  - Auto‑fit is snappy.

## Safety and Loops
- While dragging pins, fields update without jitter.
- While editing fields, pins move without oscillation.
- No double‑render or event storms in console.

## Troubleshooting
- Leaflet fails to load:
  - Check local assets in `assets/vendor/leaflet/`; CDNs: `unpkg.com`, `jsdelivr.net`.
  - Network may block tile servers; the map auto‑switches between sources.
- CSRF or auth errors:
  - Ensure admin session is active.
- Rate limits:
  - None expected in Admin UI for local actions; group creation follows server rules.

---
If any issues are found, capture console logs and the JSON details from the creation response for triage.

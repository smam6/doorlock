# Legacy files (not used by DoorLock 2.0)

These files are kept for reference only. They are excluded from the
application by the shipped web-server rules (Apache `.htaccess`, nginx
example and the development server) and are never loaded by the new code.

- `api/arduino.php` — unauthenticated-by-design SSRF proxy to a hardcoded
  private IP. Replaced by the signed device API and admin panel.
- `includes/functions.php` — helpers for the old Arduino proxy.
- `database.sql` — original schema with a placeholder password hash.
  Superseded by `database/migrations/{mysql,sqlite}/001_initial.sql`.
- `firmware/RFID_thingPod/` — original sketches with a blocking relay delay,
  hardcoded Wi-Fi credentials and conflicting relay polarity. Superseded by
  `firmware/doorlock-esp8266/`.
- `assets/` — original front-end assets that contained duplicate handlers and
  dead Express code. Superseded by `assets/css/app.css` and `assets/js/*`.

Do not deploy this directory to a web-accessible path.

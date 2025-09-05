=== RightWin QR Portal ===
Contributors: rightwin
Requires at least: 6.0
Tested up to: 6.x
Stable tag: 1.2.0
License: GPLv2 or later

QR portal with dynamic redirects, analytics, and Elementor-safe shortcodes.

== Shortcodes ==
[rwqr_portal]     Login/Register/Forgot
[rwqr_wizard]     Create QR wizard
[rwqr_dashboard]  User dashboard

== Setup ==
1) Upload plugin and Activate
2) Settings → Permalinks → Save (flush)
3) Create pages:
   /portal    → [rwqr_portal]
   /create    → [rwqr_wizard]
   /dashboard → [rwqr_dashboard]
4) RightWin QR → Settings: set Max Logo MB + Contact HTML

== Notes ==
- Dynamic short links: /r/{alias}
- PDF requires Imagick (falls back to PNG)
- New registrations become Author to allow edit/uploads

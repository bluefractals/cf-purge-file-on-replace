# cf-purge-file-on-replace
Purges the exact file URL from Cloudflare when a file attachment is replaced/updated (useful for "Replace Media" workflows).

Usage:

1. In WordPress: Plugins → Add New → Upload Plugin

2. Upload the zip, install, activate

3. Go to: Settings → Cloudflare PDF Purge

4. Paste:
   - Zone ID
   - API Token (permissions: Zone → Cache Purge → Purge and Zone → Read)
   - Notification email

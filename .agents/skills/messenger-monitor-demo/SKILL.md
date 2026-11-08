---
name: messenger-monitor-demo
description: Build, reuse, start, stop, or check the seeded local Symfony demo for Messenger Monitor. Use for dashboard previews, browser testing, and README screenshots.
---

# Messenger Monitor demo

## Reuse the existing app

From the bundle checkout, look for `/tmp/messenger-monitor-demo/public/index.php` first. Verify the app loads the current checkout via its Composer path symlink or bootstrap. Reuse its SQLite data and configuration. If the demo points to another checkout, use a distinct temporary directory and loopback port.

If the app is missing, follow [Build the demo](references/build.md). Use synthetic data in the demo database. Reset an existing demo's fixtures only when requested.

## Run the demo

```sh
demo_dir=/tmp/messenger-monitor-demo
symfony server:status --dir="$demo_dir"
symfony server:start -d --no-tls --port=8765 --dir="$demo_dir"
```

Start the server if it is stopped. Check the dashboard at `http://127.0.0.1:8765/` and use the actual browser UI for visual checks. For polling changes, check the initial page and a refresh.

Leave the server running if the user wants to view it. Stop a server started only for verification when done. To stop the demo, run `symfony server:stop --dir="$demo_dir"` and verify its status.

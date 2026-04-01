# Better Multisite Cron (WordPress mu-plugin)
<p align="center">
	<img align="right" width="300" height="auto" src="./croni-l.png">
</p>

WordPress's built-in `wp-cron.php` doesn't scale on large multisites — it only handles one site per request. This plugin runs cron across all sites sequentially via WP-CLI, with time limits, error emails, and grouped log output.

- Requires [WP-CLI](https://wp-cli.org/) and WordPress Multisite
- Sends error emails when jobs fail
- Runs cron for `last_updated` blogs first (customizable order)
- Limits the overall time cron is running (`--max_seconds`)
- Compact log files by default, verbose with `--log_verbose`

## Usage

```bash
wp multisite-cron run [--max_seconds=900] [--log_verbose] [--overtime_is_error] ...
```

## Example

You could have two crontab entries:
- One running every 30 min for max 15 min, caring about active blogs first.
- Another running daily for 10 hours doing everything else, sending an email if time was not enough.

```bash
# trigger every 30 mins, run for max 15 min (900s). don't treat overtime as error.
*/30 * * * * cd /srv/www/current && wp multisite-cron run --log_errors_to_file='/srv/www/logs/better-cron.log' --max_seconds=900 > /dev/null 2>&1

# trigger daily at midnight, run for 10 hrs, treat overtime as error (sends email).
0 0 * * * cd /srv/www/current && wp multisite-cron run --log_errors_to_file='/srv/www/logs/better-cron.log' --max_seconds=36000 --overtime_is_error > /dev/null 2>&1
```

## Options

| Option | Default | Description |
|---|---|---|
| `always_add_blog_ids` | `'1'` | Comma-separated blog IDs to always include and prioritize. |
| `debug` | `false` | More verbose CLI output. |
| `email_to` | network `admin_email` | Email address for error notifications. |
| `include_archived` | `false` | Run cron for archived blogs? |
| `limit_last_updated_months` | `null` | Only include blogs updated in the last X months. |
| `limit` | `null` | Limit to X blogs. `null` = no limit. |
| `log_errors_to_file` | `false` | Absolute path to error log file. |
| `log_success_to_file` | `false` | Absolute path to success log file. |
| `log_verbose` | `false` | Include `args`, `query` and per-blog `cmd`/`response` in log files. |
| `log_max_size` | `20971520` (20 MB) | Max log file size in bytes before erroring. |
| `max_seconds` | `0` | Stop starting new blogs after X seconds. `0` = no limit. |
| `order_by` | `'last_updated DESC, blog_id ASC'` | SQL ORDER BY for blog processing order. |
| `overtime_is_error` | `false` | Treat overtime as an error (triggers email). |
| `send_error_email` | `true` | Send an email when errors occur? |
| `skip_all_plugins` | `false` | CLI only. Pass `--skip-plugins` to sub-commands. |
| `skip_all_themes` | `false` | CLI only. Pass `--skip-themes` to sub-commands. |

## Log output

Blog tasks are grouped: blogs with identical state (same `job_names`, `over_time`, etc.) are merged into a single entry with a `blog_ids` array. This keeps logs compact on large multisites.

**Default** (compact) — no `args`/`query_all_blogs`, no per-blog `cmd`/`response`/`site_url`/`issue`:

```json
{
  "2026-03-17 17:30:02": {
    "error_count": 0,
    "duration_all_seconds": 900.3,
    "blog_tasks": [
      {
        "over_time": 0,
        "job_names": ["wp_queue_connections_databaseconnection", "scoped_notify_process_queue"],
        "duration_blog_seconds": 0.96,
        "blog_ids": ["1"]
      },
      {
        "over_time": 0,
        "job_names": ["do_pings"],
        "duration_blog_seconds": 2.13,
        "blog_ids": ["1235"]
      },
      {
        "over_time": 0,
        "job_names": [],
        "blog_ids": ["6129", "3967", "...3855 more"]
      },
      {
        "over_time": 1,
        "job_names": [],
        "blog_ids": ["4938", "1416", "...124 more"]
      }
    ]
  }
}
```

*(blog_ids truncated for readability — actual output contains all IDs)*

**`--log_verbose`** — same structure, but includes `args`, `query_all_blogs` at the top level, and `cmd`, `response`, `site_url`, `issue` per blog task. Less grouping happens since per-blog fields differ.

## Todos

- [ ] Add a log-table?
- [ ] Add tests
- [ ] Make it usable via backend (not just WP-CLI)

# Better Multisite Cron (WordPress mu-plugin)
<p align="center">
	<img align="right" width="300" height="auto" src="./croni-l.png">
</p>

WordPress's built-in `wp-cron.php` doesn't scale on large multisites — it only handles one site per request. This plugin runs cron across all sites sequentially via WP-CLI, with time limits, a record of every run, and grouped log output.

- Requires [WP-CLI](https://wp-cli.org/) and WordPress Multisite
- Records every run for a week: in `wp multisite-cron status` and in Network Admin → Settings
- Runs cron for `last_updated` blogs first (customizable order)
- Limits the overall time cron is running (`--max_seconds`)
- Compact log files by default, verbose with `--log_verbose`
- Mails the network admin when a run went wrong (`--email_to`, `--no-send_error_email`) — what
  went wrong and where to read it, never the error message itself
- Exits with code 1 on errors, and fires one action per run, so failures and silence are both detectable

## Usage

```bash
wp multisite-cron run [--max_seconds=900] [--log_verbose] [--overtime_is_error] ...
wp multisite-cron status [--name=quick] [--max_age=1800] [--all]
```

## Example

You could have two crontab entries:
- One running every 30 min for max 15 min, caring about active blogs first.
- Another running daily for 10 hours doing everything else, sending an email if time was not enough.

```bash
MAILTO=ops@example.org

# trigger every 30 mins, run for max 15 min (900s). don't treat overtime as error.
*/30 * * * * flock -n /tmp/bmsc-quick.lock sh -c 'cd /srv/www/current && wp --quiet multisite-cron run --name=quick --log_errors_to_file=/srv/www/logs/better-cron.log --max_seconds=900' > /dev/null

# trigger daily at midnight, run for 10 hrs, treat overtime as error (exits 1, so cron mails).
0 0 * * * flock -n /tmp/bmsc-daily.lock sh -c 'cd /srv/www/current && wp --quiet multisite-cron run --name=daily --log_errors_to_file=/srv/www/logs/better-cron.log --max_seconds=36000 --overtime_is_error' > /dev/null
```

Why the entries look like that:

- **`> /dev/null`, not `> /dev/null 2>&1`.** cron mails whatever the command prints, and this command
  is chatty on stdout. Discarding stdout only leaves stderr, where the PHP fatals, the OOM, the
  `wp: command not found` and the failed `cd` end up. With `MAILTO` set you now get mail when
  something actually breaks, and nothing otherwise. `--quiet` additionally silences the per-blog
  notices in case you want to keep stdout.
- **`flock -n`** keeps an entry from overlapping itself. `--max_seconds=900` on a `*/30` schedule is
  fine, but `--max_seconds=900` on a `*/5` schedule guarantees three concurrent runs, and two
  processes can pick up the same due event. One lock file per entry; do not share one between them
  if the long daily run is meant to keep going while the short one fires.
- **`--name`** keeps the runs apart in `wp multisite-cron status`.

## Did it run? (`wp multisite-cron status`)

Every run is recorded in the network-option `bmsc_runs`, grouped by `--name` and kept for 7 days
(filter `better_multisite_cron_retention_days`). The record is written *before* the work starts and
completed afterwards, so a run that died (fatal, OOM, kill) stays visibly unfinished instead of
leaving no trace at all. The newest run per name is never pruned: a cron that stopped a month ago
must not look like a cron that never existed.

```bash
$ wp multisite-cron status --name=quick --max_age=1800
+-------+---------------------+---------------------+------------------+-----------------+-------------+---------+
| name  | started             | finished            | duration_seconds | blogs_processed | error_count | problem |
+-------+---------------------+---------------------+------------------+-----------------+-------------+---------+
| quick | 2026-08-10 14:00:02 | 2026-08-10 14:03:11 | 189.4            | 12              | 0           |         |
+-------+---------------------+---------------------+------------------+-----------------+-------------+---------+
Success: 1 run(s), no problems.
```

Without `--name` you get the last run of every name, with `--all` every run still kept. It exits
with **1** if a run died, logged an error, or finished longer than `--max_age` seconds ago.
`wp multisite-cron run` itself also exits with 1 when anything went wrong (disable with
`--no-exit_on_error`), so `||`, a systemd `OnFailure=` or any monitoring hook works.

An empty error log alone can never tell you whether nothing failed or nothing ran; this can.

The same records are shown at the bottom of **Network Admin → Settings**, read-only: one row per
cron, because that is the thing that has a state. The last run's result is spelled out, and the
history is one mark per day in the same row — hover a mark for that day's counts and problems:

```
Cron    Last run                    Result                             Last 7 days
quick   2026-08-13 14:00 (12m ago)  ✔ Success, jobs in 12 of 3867      ✔ ✔ ✔ ✘ ✔ · ✔
        took 189 s                  blogs
daily   2026-08-13 00:00 (14h ago)  ✘ Jobs failed or were skipped in   · · ✔ ✔ ✔ ✔ ✘
        took 10 h                   97 of 200 blogs.
                                    ▸ What it said
```

`✔` a day where every run was fine, `✘` a day where one failed, `·` a day where nothing ran at all —
which is the failure no error log can report, and the reason the day marks exist.

**"What it said"** unfolds the error message itself. This screen (and a shell) is the only place it
is shown, which is what lets the mail leave it out; it needs `manage_network_options`, like every
other network setting. Link straight at it with `Better_Multisite_Cron\Network_Settings::url()`.

## Reporting a run

### The error mail

A run that went wrong is mailed to the network's `admin_email`, which is the reporting nobody has
to set up. Aim it somewhere else with `--email_to=ops@example.org`, turn it off with
`--no-send_error_email`:

```
Subject: [Example] Multisite cron "quick" failed

Cron:     quick
Problem:  Jobs failed or were skipped in 97 of 200 blogs.
Started:  2026-08-13 14:00:02
Finished: 2026-08-13 14:15:11 (909.4 s)
Blogs:    12 of 3867 ran a job
Host:     web01 (pid 4711)

Error message:
  https://example.org/wp-admin/network/settings.php#bmsc-runs
  wp multisite-cron status --name=quick --all
  Error log /srv/www/logs/better-cron.log, at the entry around 2026-08-13 14:00:02 Europe/Berlin
```

**The mail carries no error message**, only what went wrong (`Run_Log::summary()`: a death, a
count of failed blogs, or a run that stopped) and where to read the rest. An error message quotes
whatever the failing job touched — urls, addresses, whatever ended up in an exception — and mail
travels through servers and sits in inboxes, while the message itself was already behind a
network-admin login. So it stays there, and the mail links at it.

One mail per failed run, none on success. A run that was killed or ran out of memory cannot mail
you — it is the crontab's `MAILTO` that reports those (stderr), and the record that keeps them
visible afterwards.

### The action (`better_multisite_cron_finished`)

The mail is just a listener of the action every finished run fires, and so is anything else you
consider reporting — a logger, a monitoring ping, a chat message:

```php
add_action(
	'better_multisite_cron_finished',
	function ( array $run, array $config ) {
		if ( empty( $run['error'] ) ) {
			return;
		}
		// $run: name, started, finished, duration_seconds, blogs_found, blogs_processed, error_count, error
		error_log( "Multisite cron '{$run['name']}' failed: {$run['error']}" );
	},
	10,
	2
);
```

`$run['error']` is empty on success, and holds the fatal/OOM message when the shutdown handler had
to write the record. Failed jobs are one line each, job, blog and the line of stderr that names
the error, with identical failures sharing a line:

```
Jobs failed or were skipped in 5 blogs:
- do_pings (+2 after it) in https://example.org/foo (blog 1234): PHP Fatal error: Uncaught TypeError: … in /srv/b.php:12
- 4 blogs (7, 8, 9, … +1): skipped, max_seconds was reached
```

A blog's due jobs run in one `wp cron event run --due-now`, so a job that dies takes the ones
after it along. The job named is the first one that did not report `Executed the cron event`: the
one that failed, or, if WordPress died while loading, the first one that never started.

The record keeps 500 characters (`Run_Log::MAX_ERROR_CHARS`), so the command and the job's output
are left out; the error log file has both (`--log_verbose`). `Run_Log::summary( $run )` gives you the same thing without quoting the run,
for anything that leaves the server. The mail itself hangs on the same hook, so it can be thrown
out whole:

```php
remove_action( 'better_multisite_cron_finished', 'Better_Multisite_Cron\send_error_mail' );
```

## Options

| Option | Default | Description |
|---|---|---|
| `always_add_blog_ids` | `'1'` | Comma-separated blog IDs to always include and prioritize. |
| `debug` | `false` | More verbose CLI output. |
| `email_to` | the network's `admin_email` | Who gets the mail when a run went wrong. |
| `exit_on_error` | `true` | CLI only. Exit with code 1 if the run had errors (`--no-exit_on_error` to disable). |
| `include_archived` | `false` | Run cron for archived blogs? |
| `limit_last_updated_months` | `null` | Only include blogs updated in the last X months. |
| `limit` | `null` | Limit to X blogs. `null` = no limit. |
| `log_errors_to_file` | `''` | Absolute path to error log file. |
| `log_success_to_file` | `''` | Absolute path to success log file. |
| `log_verbose` | `false` | Include `args`, `query` and per-blog `cmd`/`response` in log files. |
| `log_max_size` | `20971520` (20 MB) | Max log file size in bytes before erroring. |
| `max_seconds` | `0` | Stop starting new blogs after X seconds. `0` = no limit. |
| `name` | `'default'` | Name of this run in `wp multisite-cron status`. Give each crontab entry its own. |
| `order_by` | `'last_updated DESC, blog_id ASC'` | SQL ORDER BY for blog processing order. |
| `overtime_is_error` | `false` | Treat overtime as an error (exit code 1, recorded as a problem). |
| `send_error_email` | `true` | Mail `email_to` if the run went wrong (`--no-send_error_email` to disable). |
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

## Tests

```bash
composer install && composer test
```

They stub the handful of WordPress functions the run log uses (`tests/bootstrap.php`), so the suite
needs no WordPress and runs in milliseconds. Covered: the record (grouping, retention, pruning,
problems), the run skeleton (error path, the action, switch normalisation, the shutdown handler),
the error mail (when it goes out and what it says) and the admin report - the last two including a
record from an older version, which must break neither the mail nor the screen.

## Todos

- [ ] Make it usable via backend (not just WP-CLI)

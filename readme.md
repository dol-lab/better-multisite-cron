# Better Multisite Cron (WordPress mu-plugin)
<p align="center">
	<img align="right" width="300" height="auto" src="./croni-l.png">
</p>

- 🤷‍♂️ currently requires WP-CLI (but can easily be extended)
- ✅ Sends ugly emails if things go south
- ✅ run cron for ``last_updated`` blogs first (customize order)
- ✅ limit the overall time cron is running
- ✅ very basic log file, just use for testing

## Todos

Trigger with ``wp multisite-cron run``.

- [ ] create a log-table?
- [ ] add some testing...
- [ ] would run_command (instead of runcommand) also work? faster?
- [ ] make it usable via backend (instead of wp-cli). add a class-multisite-cron-request
- [ ] i18n
- [x] I'm using ``switch_to_blog`` and ``wp_get_ready_cron_jobs`` to check if there are jobs in a blog.
      Does this include plugins (if the plugin is not active in the root-blog)? - It does.

## Example

You could have two jobs:
- one is running every 30 mins and for max 15mins caring about active blogs first. no
- another one running daily for 10hours doing everything else -> sending you an email, if the time was not enough to run everything.
- both send emails to the site admin_email if things go wrong.

```bash
# your crontab

# trigger every 30 mins, run for max 15min(900s). don't treat overtime as error.
*/30 * * * * cd /srv/www/current && (wp multisite-cron run --log_errors_to_file='/srv/www/logs/better-cron.log' --max_seconds=900 ) > /dev/null 2>&1
# trigger every day at midnight, run for 10hrs, treat overtime as error (and send an email).
0 0 * * * cd /srv/www/current && (wp multisite-cron run --log_errors_to_file='/srv/www/logs/better-cron.log' --max_seconds=36000 --overtime_is_error ) > /dev/null 2>&1

```


## Options
```php
# lazy copy from code:
'always_add_root_blog'      => true, // always add the root blog (first) to the list of blogs to run cron for.
'debug'                     => false, // more verbose output.
'email_to'                  => get_network_option( get_current_network_id(), 'admin_email' ),
'include_archived'          => false, // run cron for archived blogs?
'limit_last_updated_months' => null, // number. limit to blogs, which were updated in the last x months.
'limit'                     => null, // limit to x blogs. null = no limit.
'log_errors_to_file'        => false, // log errors to a file (absolute path). null = no logging.
'log_max_size'              => ( 1 * 1024 * 1024 * 10 ), // 10MB. max size of the log file.
'log_success_to_file'       => false, // WIP! log success to a file (absolute path). null = no logging.
'max_seconds'               => 0, // don't run cron for the next blog, if it is over time. 0 = no limit.
'order_by'                  => 'last_updated DESC, blog_id ASC', // run new blogs first, because they are more important?
'overtime_is_error'         => false, // treat it as an error, if max_seconds was not enough to finish all jobs.
'send_error_email'          => true, // send an email, if an error occurred?
'skip_all_plugins'          => false, // CLI only. careful: --skip-plugins does something else...
'skip_all_themes'           => false, // CLI only.
'sleep_between'             => 0.02, // sleep x seconds between each blog. 🤷‍♂️
```

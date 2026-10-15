# Log Watcher

A production-quality Drupal 10/11 module that prevents important Drupal logging errors from being overlooked.

## Overview

Log Watcher captures important Drupal log events, aggregates similar errors into manageable groups, provides an administrative reporting dashboard, and periodically emails administrators a concise digest with links back to the Drupal site.

On busy sites, important errors can be drowned out by large numbers of less important watchdog messages, and administrators sometimes only discover serious problems after a client or user reports them. Log Watcher solves this by:

1. **Capturing** important log events as they occur using Drupal's central PSR-3 logging architecture
2. **Grouping** similar errors together intelligently so you see patterns instead of individual occurrences
3. **Summarizing** error information so you don't lose diagnostic details
4. **Alerting** administrators via email digests at a configured frequency
5. **Reporting** with a clean administrative dashboard for investigation

## Features

### Core Monitoring

- Captures log events at configurable severity levels (Emergency, Alert, Critical, Error, Warning, etc.)
- Groups similar errors intelligently using deterministic fingerprinting
- Maintains occurrence counts and tracks first/last seen timestamps
- Preserves representative diagnostic details from each group
- Works independently of Drupal's Database Logging module

### Error Grouping

The fingerprinting strategy is conservative and reliable:

- Considers: channel, severity, exception class, normalized message, file/line information, stack trace
- Normalizes: UUIDs, numeric IDs, timestamps, email addresses, URLs, IP addresses, tokens
- Avoids aggressive fuzzy matching that could incorrectly combine unrelated errors
- Prefers stable source information (exception class, file, line) over request-specific details

### Administrative Dashboard

Located at `/admin/reports/log-watcher`:

- Summary statistics (active groups, occurrences, counts by severity)
- Sortable/filterable list of error groups
- Links to detailed investigation pages
- Real-time update as errors occur
- Status tracking (New, Active, Resolved, Ignored)

### Error Group Details

Located at `/admin/reports/log-watcher/group/{id}`:

- Full diagnostic information (exception class, file, line, stack trace)
- Representative context data
- Recent occurrences (bounded for performance)
- Quick actions (Mark Resolved, Ignore, Delete)
- Links to original watchdog entries when available

### Email Digests

- Scheduled delivery (every cron run, daily, weekly, monthly, every Friday, etc.)
- Concise summaries with most critical groups
- Links directly to error group details
- Configurable recipients (one per line, validated)
- Optional "no errors" confirmation emails
- Failure tracking and logging

### Status Workflow

- **New**: Automatically assigned to newly discovered error groups
- **Active**: An error group currently being monitored and reported
- **Resolved**: Administrator marked it as fixed (but can be reopened if it occurs again)
- **Ignored**: Administrator acknowledged it and chose not to receive notifications

### Data Retention & Performance Safeguards

- Configurable maximum number of active error groups (prevents unbounded growth)
- Bounded recent occurrences per group (default: 50)
- Automatic cleanup of old/resolved/ignored groups based on retention periods
- Efficient database indexes for O(1) lookups
- No full-table scans or memory explosions with high-volume logging
- Safe handling of concurrent group creation (race condition resistant)

### Maintenance & Cleanup

Located at `/admin/reports/log-watcher/maintenance`:

- Clear resolved groups
- Clear ignored groups
- Clear all Log Watcher data (with confirmation)
- Run cleanup manually or let cron handle it

## Installation

Log Watcher is packaged to work like a self-hosted contrib module.

### Recommended: install from your Git repository

Add your self-hosted Git repository to the consuming project's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:gradyshingler/log_watcher.git"
    }
  ]
}
```

Then require the module with Composer:

```bash
composer require gradyshingler/log_watcher:^1.0
```

Composer will install it into `web/modules/contrib/log_watcher/` in a standard Drupal recommended-project layout.

### Local development with one shared source repository

For active development across multiple Drupal projects, use a Composer path repository pointing at your single working copy:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../dvinci-contrib/log_watcher",
      "options": {
        "symlink": true,
        "versions": {
          "gradyshingler/log_watcher": "dev-main"
        }
      }
    }
  ]
}
```

Then require the development branch:

```bash
composer require gradyshingler/log_watcher:dev-main -W
```

With `symlink: true`, edits in your shared module repository are reflected immediately in every local project consuming it.

### Exact local workflow used in `usgpo-bens-guide`

The current local setup uses:

- shared source repository: `/Users/gshingler/Documents/dvinci_docker/docker-shared/htdocs/dvinci-contrib/log_watcher`
- consuming project: `/Users/gshingler/Documents/dvinci_docker/docker-shared/htdocs/usgpo-bens-guide`
- Composer path repository: `../dvinci-contrib/log_watcher`

Example root-project Composer configuration:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../dvinci-contrib/log_watcher",
      "options": {
        "symlink": true,
        "versions": {
          "gradyshingler/log_watcher": "dev-main"
        }
      }
    }
  ],
  "require": {
    "gradyshingler/log_watcher": "dev-main"
  }
}
```

After adding that configuration, install or refresh the dependency with:

```bash
composer update gradyshingler/log_watcher -W
drush cr
```

If Composer is not available in the current shell, make the change in your usual local PHP/Composer environment and then rebuild Drupal caches.

### Enable and configure

1. Enable via admin UI or `drush en log_watcher`
2. Run database updates to create tables: `drush updatedb`
3. Rebuild caches: `drush cr`
4. Configure at `/admin/config/system/log-watcher`

### Repository layout recommendation

To keep one source of truth, move this module into its own Git repository with the module files at the repository root. A common local layout is:

```text
~/Sites/
  dvinci-contrib/
    log_watcher/        # standalone Git repository
  project-a/
  project-b/
```

Each Drupal project then consumes that repository through Composer instead of copying the module into `custom/`.

### Moving from local path development to tagged releases

For local development, stay on `dev-main` with the path repository above.

When you want to publish a stable reusable version:

1. Commit the latest changes in the standalone `log_watcher` repository.
2. Create and push a Git tag such as `1.0.0`.
3. Replace the consuming site's path repository with a VCS repository entry.
4. Change the requirement from `dev-main` to a tag such as `^1.0`.

Example commands from the standalone repository:

```bash
git add .
git commit -m "Prepare 1.0.0 release"
git tag 1.0.0
git push origin main --tags
```

Then in each consuming Drupal project:

```bash
composer require gradyshingler/log_watcher:^1.0 -W
composer update gradyshingler/log_watcher -W
drush cr
```

## Configuration

### Monitoring

- **Enable/Disable**: Toggle Log Watcher on/off
- **Severity Levels**: Select which RFC 5424 severity levels to capture (Emergency, Alert, Critical, Error, Warning, Notice, Informational, Debug)

### Email Digest

- **Digest Frequency**: How often to send emails (every cron run, daily, weekly, monthly, every Friday)
- **Recipients**: One email address per line
- **Send No-Error Digest**: Whether to send confirmation emails when no errors occurred
- **Subject Prefix**: Custom prefix for email subjects
- **Max Groups Per Email**: Limit email length by capping number of error groups included

### Retention & Limits

- **Max Active Groups**: Maximum error groups to maintain (default: 1000). Oldest groups deleted when limit reached.
- **Max Occurrences Per Group**: Only keeps this many recent occurrences per group (default: 50)
- **Retention Periods**:
  - Active groups: default 90 days
  - Resolved groups: default 30 days
  - Ignored groups: default 14 days

## Email Scheduling

Important distinction:

- **Log Capture**: Happens immediately when Drupal logs an event (real-time)
- **Digest Delivery**: Happens on cron according to configured frequency

If your Drupal site runs cron every 3 hours and you've configured weekly digests, the email will send on the first cron run after the weekly interval elapses. Actual delivery time depends on when cron executes.

### Frequency Options

- `every_run`: Send on every cron execution
- `daily`: Once per day (interval since last send)
- `every_2_days`: Once every 2 days
- `weekly`: Once per week (default)
- `biweekly`: Once every 2 weeks
- `monthly`: Once per month
- `every_friday`: Every Friday

## Permissions

Log Watcher defines four dedicated permissions:

1. **Administer Log Watcher configuration** - Access settings form
2. **View Log Watcher reports** - Access dashboard and group details
3. **Manage error groups** - Resolve, reopen, ignore, modify groups
4. **Delete Log Watcher data** - Clear groups and perform maintenance

Assign these to appropriate roles. Admin role automatically has all of them.

## Cron Requirements

Log Watcher depends on Drupal cron for:

1. Determining if a digest is due (compare last send time to configured frequency)
2. Building and sending digests
3. Maintenance and data cleanup

Make sure Drupal cron runs regularly. A site with only error capture but no cron will still capture groups but will never send digests or clean up old data.

## Drush Commands

Log Watcher provides convenient Drush commands:

```bash
# Check status and show top error groups
drush log-watcher:check

# Send digest manually (respects configured recipients)
drush log-watcher:send

# Run cleanup manually
drush log-watcher:cleanup

# Clear data (with confirmation)
drush log-watcher:clear [--status=resolved|ignored|all] [--force]
```

Examples:

```bash
# View current status
drush lwc

# Send digest now
drush lws

# Clear all resolved groups (with confirmation prompt)
drush lwcr --status=resolved

# Force clear all data without prompting
drush lwcr --status=all --force
```

## Data Privacy

Log Watcher is conservative about sensitive data:

### Does NOT Store

- Passwords, tokens, credentials
- Session data
- Full request bodies
- Private keys or authentication secrets

### Does Sanitize

- IP addresses are hashed (not stored plainly)
- URLs have query parameters stripped
- Sensitive context keys are filtered out

### Does Store (safely)

- Error messages and backtraces (necessary for diagnosis)
- Request URIs (path only, no query params)
- HTTP referrers
- User IDs (not usernames/emails)
- Relevant exception context data

Administrators with access to the database or reports will see diagnostic information. Control access via permissions.

## Error Grouping Details

### Fingerprinting Strategy

The fingerprint is a SHA256 hash of these components (in order of priority):

1. **Channel** - Drupal logger channel (always included)
2. **Severity** - RFC 5424 level (always included)
3. **Exception Class** - If exception is thrown (when available, takes priority)
4. **Normalized Message** - Message with dynamic values replaced
5. **File & Line** - Source file and line number (when available)
6. **Stack Trace Hash** - MD5 of sanitized stack trace (when available)

### Normalization Examples

The system recognizes and normalizes:

- Numeric IDs: `"Node 12345"` → `"Node {ID}"`
- UUIDs: Various formats → `"{UUID}"`
- Timestamps: Unix, ISO 8601 → `"{TIMESTAMP}"`
- Emails: `user@example.com` → `"{EMAIL}"`
- URLs: Full URLs → `"{URL}"`
- IPv4 addresses: `192.168.1.1` → `"{IP}"` (when they look variable)
- Hex tokens: 32+ hex chars → `"{TOKEN}"`

### Why This Approach

- **Stable**: Same underlying problem always produces the same fingerprint
- **Conservative**: Only replaces obvious variable patterns
- **Source-aware**: Prefers exception information over message text
- **Traceable**: Stack trace information improves grouping accuracy

This avoids false positives while ensuring related errors are grouped together.

## Database Schema

### Tables Created

1. **log_watcher_error_groups**: Main error group records
   - Indexes on: fingerprint (unique), severity+status, channel+severity, last_seen, first_seen, occurrence_count, status
   - ~50-100 bytes per group + text fields

2. **log_watcher_occurrences**: Recent individual occurrences (bounded per group)
   - Indexes on: group_id, occurred_at, group_id+occurred_at
   - ~500-1000 bytes per occurrence (text is limited)
   - Automatically pruned to max configured per group

3. **log_watcher_digest_state**: Singleton record tracking digest sends
   - Last send time, last check time, failure count, last error message

### Performance Characteristics

- Finding/incrementing a group: O(1) fingerprint lookup + O(1) update
- Listing groups: Indexed queries with limits
- Cleanup: Indexed range queries, batch deletes
- No full-table scans in normal operation
- Concurrent safety via unique fingerprint constraint

## Troubleshooting

### "No email recipients configured"

You must enter at least one email address in the settings. Ensure:
- At least one email is configured
- Each email is on its own line
- Emails are valid format (checked on save)

### Digests not sending

Check:
1. Log Watcher is enabled
2. At least one email recipient configured
3. Drupal cron is running regularly (check admin/reports/status)
4. Configured frequency is not longer than your actual wait time
5. Check watchdog logs for send failures: `drush watchdog:show --type=log_watcher`

### Groups not appearing

Check:
1. Log Watcher is enabled
2. Severity levels match what's being logged
3. An error at that severity level actually occurred
4. Check watchdog for any Log Watcher capture errors

### Memory issues with large volumes

Log Watcher is designed for safety under high log volume:
- Groups are aggregated (not individual rows per event)
- Recent occurrences are bounded
- Queries use indexes and limits
- No batch loading of entire tables

If experiencing issues:
1. Lower `max_active_groups` setting
2. Lower `max_occurrences_per_group` setting
3. Run cleanup more frequently
4. Ensure database indexes exist (check install hooks)

## Differences from Database Logging

**Log Watcher is NOT a replacement for Drupal's Database Logging module.**

- Database Logging captures everything; Log Watcher is selective
- Database Logging stores individual entries; Log Watcher aggregates by fingerprint
- Database Logging is for auditing; Log Watcher is for alerting
- You can run both simultaneously

Log Watcher is complementary - it helps admins focus on important issues while Database Logging provides a complete audit trail.

## Performance Considerations

### What Log Watcher Does NOT Do

- Load full watchdog tables into memory
- Process unlimited logs in one request
- Send hundreds of emails
- Build huge HTML documents
- Use expensive fuzzy matching across millions of rows

### What Log Watcher DOES Do

- Lookup groups via indexed fingerprint (fast)
- Increment atomic counter (safe, fast)
- Store bounded recent occurrences (efficient)
- Use cron for all heavy processing
- Implement data retention limits
- Use paging for reports

### Tuning for Your Site

High-volume sites should:
1. Ensure database has proper indexes (auto-created on install)
2. Lower `max_active_groups` if needed (fewer groups = fewer storage)
3. Lower `max_occurrences_per_group` (50 is usually enough for diagnosis)
4. Increase `retention_days` only if you need historical analysis
5. Run cron regularly so cleanup happens before tables grow

## Development & Extension

The module is designed to be maintainable and extensible:

### Service-Oriented Architecture

- `ErrorFingerprintService`: Pluggable grouping algorithm
- `ErrorGroupService`: Core group management
- `OccurrenceService`: Occurrence recording
- `NotificationService`: Email sending
- `MaintenanceService`: Cron and cleanup
- `DigestBuilder`: Email content formatting
- `ConfigService`: Settings management
- Multiple repositories for data access

### Improving Fingerprinting

To enhance error grouping for your site, override `ErrorFingerprintService::normalizeMessage()` or create a custom fingerprinting service.

### Custom Email Format

Extend `DigestBuilder` to customize email content and format.

### Additional Drush Commands

Create custom Drush commands that use the repositories and services.

## Known Limitations

1. IP addresses are hashed and cannot be un-hashed (by design)
2. The module requires Drupal cron to function (digests, cleanup)
3. Emails are plain text (rich HTML would require a mail formatter)
4. Stack traces are truncated after 16KB (to limit storage)
5. Context arrays are truncated after 16KB (to limit storage)

## Testing

Run tests with:

```bash
drupal test:run log_watcher
```

If you are developing through a symlinked path repository, run the tests from a consuming Drupal site so Drupal's full test infrastructure and dependencies are available.

Test coverage includes:
- Configuration form validation
- Error fingerprint generation
- Error grouping accuracy
- Occurrence recording and cleanup
- Status transitions
- Digest generation
- Email functionality
- Permission checks
- Retention policies
- Race condition handling
- High-volume safety

## Support & Contribution

This module is designed to live in a standalone Git repository and be reused across multiple Drupal projects through Composer. It follows Drupal coding standards and is structured like a contrib-style package.

### Recommended release workflow

1. Develop in the standalone `log_watcher` repository.
2. Commit changes on `main`.
3. Tag semantic releases such as `1.0.0`, `1.1.0`, or `1.1.1`.
4. In consuming projects, update with Composer:

```bash
composer update gradyshingler/log_watcher --with-dependencies
```

For local debugging, stay on `dev-main` via a path repository. For shared environments, prefer tagged releases.

For issues or enhancements, document:
- Drupal version
- PHP version
- Error message and watchdog logs
- Steps to reproduce
- Expected vs actual behavior

## License

GPL-2.0-or-later

## Author

Grady Shingler
# ZS-Sync

A WordPress synchronization system to index data on site A and allow sites B, C, D, etc. to pull changes from site A.

It's a work in progress. Here's how you can play with the parts
that are in place today.

## Installation

1. Clone this repository into your WordPress plugins directory
1. Install dependencies: `composer install`
1. Symlink this repository into your authoritative site's WordPress plugins directory
1. Activate the plugin

## Usage Examples

### 1. Scanning Authoritative Site (`scan-authoritative-site-for-changes.php`)

This script scans your authoritative WordPress site for changes in:

- Directory contents
- File deletions
- Database table changes

You'll need to edit the script to configure the site you want to scan
and then run it:

```bash
php usage-example/scan-authoritative-site-for-changes.php
```

### 2. Syncing to Target Site (`sync-data-to-target-site.php`)

Synchronizes changes from the authoritative site to the target site. Note you don't have to set it up as a plugin there. The synchronization script is free of WordPress dependencies (for now).

You'll need to edit the script to configure the target site and then run it:

```bash
php usage-example/sync-data-to-target-site.php
```

## License

This project is licensed under the GPL v2 or later.

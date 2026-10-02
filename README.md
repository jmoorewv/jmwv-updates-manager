# JMWV Updates Manager

A lightweight WordPress plugin that gives administrators control over core, plugin, theme and translation updates: let them install automatically, keep them manual, or hide them entirely.

It exists for sites where nothing should change on its own, such as client sites where an unexpected update could break the layout, without giving up visibility into what is out of date. No framework, no custom tables, no JavaScript.

## Features

- **Per-type update modes**
  - **Core:** manual, minor and security releases automatically, all releases automatically, or disabled.
  - **Plugins and themes:** manual, automatic, or disabled (hidden entirely).
- **Per-item overrides:** set an individual plugin or theme to a different mode than the default.
- **Translations:** update automatically or not.
- **Email notifications:** when new updates become available, and when updates are installed. Configurable recipient.
- **Dashboard widget:** lists pending updates for administrators.
- **Site Health test:** flags an active theme that is not a child theme, since a theme update replaces its files.
- **Update lock:** an optional hard freeze that stops everyone, administrators included, from running updates from the dashboard. Automatic updates you have enabled still run.
- **Force update check:** runs an update check and attempts the automatic updates immediately. Useful for debugging.
- **Other options:** allow updates on git/svn installs, show update notices only to users who can update, and silence WordPress's own automatic-update emails.

Everything defaults to **manual**: you are notified, and nothing installs by itself.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later

## Installation

1. Download [`jmwv-updates-manager.zip`](https://github.com/jmoorewv/jmwv-updates-manager/releases/latest/download/jmwv-updates-manager.zip) from the latest release.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, and install it.
3. Activate **JMWV Updates Manager**.
4. Open **Settings → Updates Manager**.

Use the `jmwv-updates-manager.zip` file attached to the release, not GitHub's automatic "Source code (zip)". The source download unpacks into a differently named folder and would install as a separate plugin that cannot update itself.

To install from a clone instead, copy the repository to `wp-content/plugins/jmwv-updates-manager/`.

## Updates

The plugin updates itself from this repository's [GitHub releases](https://github.com/jmoorewv/jmwv-updates-manager/releases). When a newer release is published, it shows up on the Plugins and Updates screens like any other update. It follows the same settings as every other plugin, so it can be manual, automatic or disabled like the rest, and the update lock applies to it too.

Only published releases are used, not plain tags, drafts or pre-releases.

## Notes

- This plugin replaces WordPress's own per-plugin and per-theme auto-update toggles, and hides them.
- If `DISALLOW_FILE_MODS` is set in `wp-config.php`, all updates and installs are blocked regardless of these settings. The settings page warns about this.
- "Force update check" relies on WP-Cron running.
- Keeping updates off means missing security fixes. The default of showing notices rather than hiding them is deliberate.
- Uninstalling the plugin removes its stored settings.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

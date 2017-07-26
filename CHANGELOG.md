### 1.5.3 (2016-10-01)

  * Fixed issue in handling of uppercase letters in package names

### 1.5.2 (2016-09-02)

  * Fixed a few minor bugs

### 1.5.1 (2016-06-27)

  * Fixed one last issue in handling the public repos with only "packages"

### 1.5.0 (2016-06-23)

  * Added option to switch to a monorepo instead of dual repo model, set `monorepo: true` in app/toran/config.yml
  * Added support for mirroring public repositories that only have "packages" and no includes
  * Fixed a few minor bugs

### 1.4.4 (2016-05-03)

  * Fixed regression in 1.4.3

### 1.4.3 (2016-04-29)

  * Fixed bug handling proxying of packages.drupal.org
  * Fixed bin/cron crashing completely if one package fails to update, errors will now be printed and the process continues

### 1.4.2 (2016-04-15)

  * Fixed handling of some public repos like firegento.com that do not provide package uids

### 1.4.1 (2016-04-12)

  * Fixed bin/cron writing the configuration too often that caused it to drop new settings in some cases
  * Fixed overwriting of .htaccess on update (from next update on, no more overwriting)
  * Fixed PUT requests causing issues on some server, now using POST instead
  * Fixed bin/cron lock file failing to be removed in some cases

### 1.4.0 (2016-04-03)

  * Added option to track private package installs, set `track_downloads: true` in app/toran/config.yml to get an install log in `app/logs/downloads.private.log`
  * Added a session_save_path parameter in app/config/parameters.yml to configure where sessions are stored
  * Minor bug fixes

### 1.3.2 (2016-03-13)

  * Fixed another regression in handling of public repos

### 1.3.1 (2016-03-12)

  * Fixed upgrade issues due to the new public repo feature

### 1.3.0 (2016-03-11)

  * Added a list of other public repos to mirror on top of packagist, which lets you sync wpackagist, magento repos, etc.
  * Added support for the new style of BitBucket hooks
  * Added support for updating single packages from CLI, via `bin/cron <packagename>`
  * Fixed packagist proxy to cache provider files in web dir, which makes it a lot faster to run updates
  * Fixed bug with the automatic update of packages after they are added

### 1.2.0 (2016-03-10)

  * Added detailed listing pages for private packages
  * Added an easy way to update single private packages from the UI
  * Added a JSON API to create private packages, see `Documentation > FAQ > How to create private packages programmatically?`
  * Updated dependencies for PHP7 support and latest Composer features
  * Fixed a few minor bugs

### 1.1.7 (2015-05-07)

  * Fixed git cloning issue with some packages
  * Fixed support for ^ operator

### 1.1.6 (2015-01-07)

  * Fixed private package update hook to work with GitHub Enterprise
  * Fixed private package support for creating zip files for feature branches

### 1.1.5 (2014-12-11)

  * Fixed issues with http proxy handling
  * Fixed minor setup and update issues

### 1.1.4 (2014-09-19)

  * Fixed handling of private packages with no dist URL
  * Fixed private package update hook to work with custom ssh URLs

### 1.1.3 (2014-09-07)

  * Fixed support for package repositories

### 1.1.2 (2014-07-28)

  * Fixed disk usage management of packagist metadata
  * Fixed github hook handler to accept more URLs

### 1.1.1 (2014-06-27)

  * Fixed handling of non-standard http ports
  * Fixed private repository when Toran is run in dev environment
  * Fixed license verification on php <=5.4.8

### 1.1.0 (2014-06-19)

  * Added support for lazy loading dists of private packages
  * Added GitHub hook support
  * Added update command
  * Added documentation
  * Added new design

### 1.0.1 (2014-05-28)

  * Fixed usability/clarity issues
  * Fixed install instructions

### 1.0.0 (2014-05-27)

  * Initial release

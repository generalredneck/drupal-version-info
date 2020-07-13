# Drupal Version Info Composer Plugin

**This is still Alpha and is rough around the edges and not fully tested.**

## Install

`composer require generalredneck/drupal-version-info`

## The Problem

If you have ever run across errors where modules require another version of a module (example with d2d_migrate and migrate), you know this can be a pain with a composer workflow. Ever since we could check out Drupal modules using git, we've had issues with version info for dev versions of modules being accurate and respecting the versioning constraints developers can set in their modules.

Drush handled this by adding package information when you used `drush make` or `drush dl` and giving you a specially crafter version based on the commit you were on like `7.x-1.15+11` meaning you were 11 commits past 7.x-1.15. This allowed you to fulfill the requirements for a module that wanted >= 7.x-1.15.

Since Drush 9, `drush dl` is no recommended and make files are a thing of the past, but leaves us with the problem once again.

## What Does It Do?

On all drupal-module and drupal-theme type composer packages that are installed by source via git, this plugin will append package information like `drush dl` does in drush 8. An example may look like this for `ctools.info`

```
diff --git a/ctools.info b/ctools.info
index cacd137..f47256e 100644
--- a/ctools.info
+++ b/ctools.info
@@ -18,3 +18,8 @@ files[] = tests/math_expression_stack.test
 files[] = tests/object_cache.test
 files[] = tests/object_cache_unit.test
 files[] = tests/page_tokens.test
+
+; Information added by drupal-version-info composer plugin on 2019-12-20
+version = "7.x-1.15+10-dev"
+project = "ctools"
+datestamp = "1576870166"
```


## Alternatives

### [Git Deploy](https://www.drupal.org/project/git_deploy)

> Git Deploy lets you develop on a live site and still satisfy version requirements and get accurate results from the update status system. This makes it easier to contribute to the projects you use.
>
> Version information is added automatically when the Drupal packaging system creates a release. If you check out a contributed project from the Drupal repository with Git, it should not have any version information. Git Deploy gets the missing version information from the project's Git log.
>
> Requirements
> Version 2 of Git Deploy requires access to the git command and the ability for PHP to execute shell commands. Version 1 runs entirely in PHP, but requires that the glip library be installed.

Git Deploy is a Drupal module. It uses the power of git on the production server to identify the version using the logs. This plugin differs in that it writes the information to the info file when `composer install` or `composer update` is run much like the methodology used by Drush. This allows you to copy the files over to a server that doesn't have git, or allow the .git folder to be removed. This is typically the workflow that happens for hosts such as Pantheon and Acquia to avoid "sub-modules" and other complications with committing .git folders within a git repository.

### [Composer Deploy](https://www.drupal.org/project/composer_deploy)

> Normally drupal.org inserts version information when a project is packaged. Packages installed via Composer do not contain this information in some cases.
>
> The required version is a dev version
> Composer runs in --prefer-source mode
> Composer Deploy hooks into the Drupal update system and attempts to provide the version of modules and themes from Composer metadata

Composer Deploy is also a Drupal module. It attempts to use the data stored in vendor/composer/installed.json to get the information for your modules. This is typically very accurate for the majority of cases. This only becomes a problem for older Drupal 7 sites in which there isn't a version of this module, and when you are working with specific commits of dev modules. This isn't Composer Deploy's fault as it's a short-coming of the packagist system built for Drupal extensions. It's hard to be robust and performent if you had to generate a package variant for every commit.

Example:

`composer require drupal/composer_deploy:1.x-dev#d8cf3fccf8966fb9e45659c501741a844c41a635`

As of this writing, this commit should be described as version 8.x-1.1+1, meaning 1 commit ahead of release 8.x-1.1. Instead you will see this in installed.json

```
  "drupal": {
      "version": "8.x-1.3+1-dev",
      "datestamp": "1555315985",
      "security-coverage": {
          "status": "not-covered",
          "message": "Dev releases are not covered by Drupal security advisories."
      }
  }
```

This is the latest commit's metadata, therefore, your pinned commit is reporting it's 2 versions ahead. Additionally, you will find that if the dependencies of the -dev package has changed between your pinned and the latest, you may not have everything you need to satisfy the needs of your pinned version.

### Create a patch

Creating a patch is always an option. To mitigate this plugin as an extra dependency, you can patch the .info file. To mitigate the composer dev package problems, create a patch from the stable version that is before the commit you want and patch up to the commit by using the diff between the commit and the stable version.

## Thanks

Thank you to the community members that have made such great contributed software for us to use. In particular this project uses work and inspiration from the Drush team, Webflo, and cweagans.

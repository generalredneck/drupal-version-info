<?php

namespace GeneralRedneck\DrupalVersionInfo;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\Link;
use Composer\Plugin\PluginInterface;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Process\Process;
class Plugin implements PluginInterface, EventSubscriberInterface
{

    /**
     * @var IOInterface $io
     */
    protected $io;

    /**
     * @var string $lastCommandOutput
     */
    protected $lastCommandOutput;

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
      $this->io = $io;
    }

    /**
     * @param PackageEvent $event
     * @throws \Exception
     */
    public function postInstall(PackageEvent $event)
    {
      $supported_types = ['drupal-module', 'drupal-theme'];
      $io = $event->getIO();
      $operation = $event->getOperation();
      $package = $operation->getPackage();
      $installation_source = $package->getInstallationSource();
      $package_type = $package->getType();
      if ($installation_source !== 'source') {
        if ($io->isVerbose()) {
          $io->write("<comment>GR-DVI: Library downloaded as dist is unsupported.</comment>");
        }
        return;
      }
      $source_type = $package->getSourceType();
      if ($source_type !== 'git' ) {
        if ($io->isVerbose()) {
          $io->write("<comment>GR-DVI:Supporting git only.</comment>");
        }
        return;
      }
      if (!in_array($package_type, $supported_types)) {
        if ($io->isVerbose()) {
          $io->write("<comment>GR-DVI: Library of unsupported type $package_type. Supporting only " . implode(', ',$supported_types). " types.</comment>");
        }
        return;
      }
      if ($io->isVerbose()) {
        $io->write("<comment>GR-DVI: Supported Library of $package_type and downloaded as $installation_source.</comment>");
      }
      $installation_manager = $event->getComposer()->getInstallationManager();
      $install_path = rtrim($installation_manager->getInstaller($package->getType())->getInstallPath($package), '/');
      $drupal_project_name = str_replace('drupal/', '', $package->getName());

      $core_version = $this->grabDrupalCoreVersion($install_path);

      $core_version_branch = $core_version . ".x-" . trim(str_replace('dev', '', $package->getVersion()), '-');
      $version = $this->ComputeRebuildVersion($install_path, $core_version_branch);
      $this->executeCommand('git -C %s log -1 --pretty=format:%%ct', $install_path);
      $output = $this->getLastCommandOutput();
      $datestamp = array_shift($output);
      $this->InjectInfoFileMetadata($install_path, $drupal_project_name, $version, $datestamp);
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     */
    public static function getSubscribedEvents()
    {
        return array(
            PackageEvents::POST_PACKAGE_INSTALL => array('postInstall', 10),
            PackageEvents::POST_PACKAGE_UPDATE => array('postInstall', 10),
        );
    }

    private function grabDrupalCoreVersion($project_dir) {
      $yaml = TRUE;
      $info_files = $this->scanDirectory($project_dir, '/.*\.info.yml$/');
      if (empty($info_files)) {
        $yaml = FALSE;
        $info_files = $this->scanDirectory($project_dir, '/.*\.info$/');
      }
      if (empty($info_files)) {
        return FALSE;
      }
      return $yaml ? "8" : "7";

    }

    private function InjectInfoFileMetadata($project_dir, $project_name, $version, $datestamp) {
      // `drush_drupal_major_version()` cannot be used here because this may be running
      // outside of a Drupal context.
      $yaml_format = substr($version, 0, 1) >= 8;
      $pattern = preg_quote($yaml_format ? '.info.yml' : '.info');
      $info_files = $this->scanDirectory($project_dir, '/.*' . $pattern . '$/');
      if (!empty($info_files)) {
        // Construct the string of metadata to append to all the .info files.
        if ($yaml_format) {
          $info = $this->generateInfoYamlMetadata($version, $project_name, $datestamp);
        }
        else {
          $info = $this->generateInfoIniMetadata($version, $project_name, $datestamp);
        }
        foreach ($info_files as $info_file) {
          if (!$this->fileAppendData($info_file->filename, $info)) {
            return FALSE;
          }
        }
      }
      return TRUE;
    }
/**
 * Finds all files that match a given mask in a given directory.
 * Directories and files beginning with a period are excluded; this
 * prevents hidden files and directories (such as SVN working directories
 * and GIT repositories) from being scanned.
 *
 * @param $dir
 *   The base directory for the scan, without trailing slash.
 * @param $mask
 *   The regular expression of the files to find.
 * @param $nomask
 *   An array of files/directories to ignore.
 * @param $callback
 *   The callback function to call for each match.
 * @param $recurse_max_depth
 *   When TRUE, the directory scan will recurse the entire tree
 *   starting at the provided directory.  When FALSE, only files
 *   in the provided directory are returned.  Integer values
 *   limit the depth of the traversal, with zero being treated
 *   identically to FALSE, and 1 limiting the traversal to the
 *   provided directory and its immediate children only, and so on.
 * @param $key
 *   The key to be used for the returned array of files. Possible
 *   values are "filename", for the path starting with $dir,
 *   "basename", for the basename of the file, and "name" for the name
 *   of the file without an extension.
 * @param $min_depth
 *   Minimum depth of directories to return files from.
 * @param $include_dot_files
 *   If TRUE, files that begin with a '.' will be returned if they
 *   match the provided mask.  If FALSE, files that begin with a '.'
 *   will not be returned, even if they match the provided mask.
 * @param $depth
 *   Current depth of recursion. This parameter is only used internally and should not be passed.
 *
 * @return
 *   An associative array (keyed on the provided key) of objects with
 *   "path", "basename", and "name" members corresponding to the
 *   matching files.
 */
private function scanDirectory($dir, $mask, $nomask = array('.', '..', 'CVS'), $callback = 0, $recurse_max_depth = TRUE, $key = 'filename', $min_depth = 0, $include_dot_files = FALSE, $depth = 0) {
  $key = (in_array($key, array('filename', 'basename', 'name')) ? $key : 'filename');
  $files = array();

  // Exclude Bower and Node directories.
  $nomask = array_merge($nomask, array('node_modules', 'bower_components'));

  if (is_string($dir) && is_dir($dir) && $handle = opendir($dir)) {
    while (FALSE !== ($file = readdir($handle))) {
      if (!in_array($file, $nomask) && (($include_dot_files && (!preg_match("/\.\+/",$file))) || ($file[0] != '.'))) {
        if (is_dir("$dir/$file") && (($recurse_max_depth === TRUE) || ($depth < $recurse_max_depth))) {
          // Give priority to files in this folder by merging them in after any subdirectory files.
          $files = array_merge($this->scanDirectory("$dir/$file", $mask, $nomask, $callback, $recurse_max_depth, $key, $min_depth, $include_dot_files, $depth + 1), $files);
        }
        elseif ($depth >= $min_depth && preg_match($mask, $file)) {
          // Always use this match over anything already set in $files with the same $$key.
          $filename = "$dir/$file";
          $basename = basename($file);
          $name = substr($basename, 0, strrpos($basename, '.'));
          $files[$$key] = new \stdClass();
          $files[$$key]->filename = $filename;
          $files[$$key]->basename = $basename;
          $files[$$key]->name = $name;
        }
      }
    }

    closedir($handle);
  }

  return $files;
}

/**
 * Simple helper function to append data to a given file.
 *
 * @param string $file
 *   The full path to the file to append the data to.
 * @param string $data
 *   The data to append.
 *
 * @return boolean
 *   TRUE on success, FALSE in case of failure to open or write to the file.
 */
private function fileAppendData($file, $data) {
  if (!$fd = fopen($file, 'a+')) {
//    drush_set_error(dt("ERROR: fopen(@file, 'ab') failed", array('@file' => $file)));
    return FALSE;
  }
  if (!fwrite($fd, $data)) {
//    drush_set_error(dt("ERROR: fwrite(@file) failed", array('@file' => $file)) . '<pre>' . $data);
    return FALSE;
  }
  return TRUE;
}

    private function generateInfoIniMetadata($version, $project_name, $datestamp) {
  $matches = array();
  $extra = '';
  if (preg_match('/^((\d+)\.x)-.*/', $version, $matches) && $matches[2] >= 6) {
    $extra .= "\ncore = \"$matches[1]\"";
  }
    $extra = "\nproject = \"$project_name\"";
  $date = date('Y-m-d', $datestamp);
  $info = <<<METADATA
; Information added by drush on {$date}
version = "{$version}"{$extra}
datestamp = "{$datestamp}"
METADATA;
  return $info;
}

/**
 * Generate version information for `.info` files in YAML format.
 */
private function generateInfoYamlMetadata($version, $project_name, $datestamp) {
  $matches = array();
  $extra = '';
  if (preg_match('/^((\d+)\.x)-.*/', $version, $matches) && $matches[2] >= 6) {
    $extra .= "\ncore: '$matches[1]'";
  }
    $extra = "\nproject: '$project_name'";
  $date = date('Y-m-d', $datestamp);
  $info = <<<METADATA
# Information added by drush on {$date}
version: '{$version}'{$extra}
datestamp: {$datestamp}
METADATA;
  return $info;
}

  /**
   * Helper function to compute the rebulid version string for a project.
   *
   * Ripped this from Drush's drush_pm_git_drupalorg_compute_rebuild_version.
   * Credit to dww for writing the original in issue #1404702.
   * This does some magic in Git to find the latest release tag along
   * the branch we're packaging from, count the number of commits since
   * then, and use that to construct this fancy alternate version string
   * which is useful for the version-specific dependency support in Drupal
   * 7 and higher.
   *
   * NOTE: A similar function lives in git_deploy and in the drupal.org
   * packaging script (see DrupalorgProjectPackageRelease.class.php inside
   * drupalorg/drupalorg_project/plugins/release_packager). Any changes to the
   * actual logic in here should probably be reflected in the other places.
   *
   * @param string $project_dir
   *   The full path to the root directory of the project to operate on.
   * @param string $branch
   *   The branch that we're using for -dev. This should only include the
   *   core version, the dash, and the branch's major version (eg. '7.x-2').
   *
   * @return string
   *   The full 'rebuild version string' in the given Git checkout.
   *
   * @see https://github.com/drush-ops/drush/blob/8.x/commands/pm/package_handler/git_drupalorg.inc
   */
  private function ComputeRebuildVersion($project_dir, $branch) {
    $rebuild_version = '';
    $branch_preg = preg_quote(rtrim($branch, '.x'));

    if ($this->executeCommand('git -C %s describe --tags', $project_dir)) {
      $shell_output = $this->getLastCommandOutput();
      $last_tag = $shell_output[0];
      // Make sure the tag starts as Drupal formatted (for eg.
      // 7.x-1.0-alpha1) and if we are on a proper branch (ie. not master)
      // then it's on that branch.
      if (preg_match('/^(?<drupalversion>' . $branch_preg . '\.\d+(?:-[^-]+)?)(?<gitextra>-(?<numberofcommits>\d+-)g[0-9a-f]{7})?$/', $last_tag, $matches)) {
        // If we found additional git metadata (in particular, number of commits)
        // then use that info to build the version string.
        if (isset($matches['gitextra'])) {
          $rebuild_version = $matches['drupalversion'] . '+' . $matches['numberofcommits'] . 'dev';
        }
        // Otherwise, the branch tip is pointing to the same commit as the
        // last tag on the branch, in which case we use the prior tag and
        // add '+0-dev' to indicate we're still on a -dev branch.
        else {
          $rebuild_version = $last_tag . '+0-dev';
        }
      }
    }
    return $rebuild_version;
  }

    /**
     * Executes a shell command with escaping.
     *
     * @param string $cmd
     * @return bool
     */
    protected function executeCommand($cmd)
    {
        $io = $this->io;
        // Shell-escape all arguments except the command.
        $args = func_get_args();
        foreach ($args as $index => $arg) {
            if ($index > 0) {
                $args[$index] = escapeshellarg($arg);
            }
        }

        // And replace the arguments.
        $command = call_user_func_array('sprintf', $args);
        $output = '';
        if ($io->isVerbose()) {
            $io->write('<comment>' . $command . '</comment>');
        }
        $output = function ($type, $data) use ($io) {
            $this->lastCommandOutput = $data;
            if ($type === Process::ERR) {
                if ($io->isVerbose()) {
                    $io->write('<error>' . $data . '</error>');
                }
            } else {
                if ($io->isVerbose()) {
                    $io->write('<comment>' . $data . '</comment>');
                }
            }
        };
        $executor = new ProcessExecutor($io);
        $exit_status = ($executor->execute($command, $output) === 0);
        $this->lastCommandOutput = $executor->splitLines($this->lastCommandOutput);
        return $exit_status;
    }

    protected function getLastCommandOutput() {
      return $this->lastCommandOutput;
    }

}

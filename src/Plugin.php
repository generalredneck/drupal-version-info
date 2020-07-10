<?php

namespace GeneralRedneck\DrupalVersionInfo;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\Package\Link;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface, EventSubscriberInterface
{

    /**
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
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
      if (!in_array($package_type, $supported_types)) {
        if ($io->isVerbose()) {
          $io->write("<comment>GR-DVI: Library of unsupported type $package_type. Supporting only " . implode(', ',$supported_types). " types.</comment>");
        }
        return;
      }
      if ($io->isVerbose()) {
        $io->write("<comment>GR-DVI: Supported Library of $package_type and downloaded as $installation_source.</comment>");
      }


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
}

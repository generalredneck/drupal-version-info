<?php

namespace GeneralRedneck\DrupalVersionInfo\Plugin;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Plugin\PluginInterface;

class Plugin implements PluginInterface
{
    /**
     * @param PackageEvent $event
     * @throws \Exception
     */
    public function postInstall(PackageEvent $event)
    {

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

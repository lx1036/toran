<?php

declare(strict_types=1);
/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Service;

use Composer\Config as ComposerConfig;
use Composer\Downloader\FileDownloader;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProxyFactory
{
    private $config;
    private $sourceSyncer;
    private $toranWebDir;
    private $toranCacheDir;
    private $urlGenerator;
    private $logger;

    public function __construct(UrlGeneratorInterface $urlGenerator, Configuration $config, SourceSyncer $sourceSyncer, $toranWebDir, $toranCacheDir, LoggerInterface $logger = null)
    {
        $this->urlGenerator  = $urlGenerator;
        $this->config        = $config;
        $this->sourceSyncer  = $sourceSyncer;
        $this->toranWebDir   = realpath($toranWebDir);
        $this->toranCacheDir = $toranCacheDir;
        $this->logger        = $logger;
    }

    public function createProxy(array $repo, IOInterface $io = null, ComposerConfig $config = null)
    {
        if (!$config) {
            $config = Factory::createConfig();
        }
        if (!$io) {
            $io = new NullIO();
            $io->loadConfiguration($config);
        }
        $rfs        = Factory::createRemoteFilesystem($io, $config);
        $downloader = new FileDownloader($io, $config, null, null, $rfs);
        $downloader->setOutputProgress(false);

        return new Proxy(
            $this->urlGenerator,
            $this->config,
            $this->sourceSyncer,
            $repo['origin'],
            $repo['url'],
            $this->toranWebDir,
            $this->toranCacheDir,
            $rfs,
            $downloader,
            $this->logger
        );
    }
}

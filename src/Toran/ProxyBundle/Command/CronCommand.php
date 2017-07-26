<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Command;

use Composer\Downloader\FileDownloader;
use Composer\IO\IOInterface;
use Composer\IO\ConsoleIO;
use Composer\Package\AliasPackage;
use Composer\Config as ComposerConfig;
use Composer\Package\Version\VersionParser;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Util\ComposerMirror;
use Toran\ProxyBundle\Service\Proxy;
use Toran\ProxyBundle\Service\Configuration;
use Toran\ProxyBundle\Service\Util;
use Toran\ProxyBundle\Model\Repository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Filesystem\LockHandler;

class CronCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('toran:cron')
            ->setDescription('Runs background jobs')
            ->setDefinition(array(
                new InputArgument('packages', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Package name(s) to update, optional'),
            ))
            ->setHelp(<<<EOT
Runs periodic background jobs of Toran Proxy

Run this command with --verbose first to initialize credentials
and then set up a cron job running it every minute with
--no-interaction

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cacheDir = $this->getContainer()->getParameter('kernel.cache_dir');
        $isVerbose = (bool) $input->getOption('verbose');

        // verify writability
        $paths = array(
            $cacheDir => false,
            realpath($this->getContainer()->getParameter('toran_web_dir')).'/repo/private' => false,
            realpath($this->getContainer()->getParameter('toran_web_dir')).'/repo/packagist' => false,
            realpath($this->getContainer()->getParameter('toran_web_dir')).'/repo' => false,
            $this->getContainer()->getParameter('toran_cache_dir') => false,
            $this->getContainer()->getParameter('composer_home') => false,
            $this->getContainer()->getParameter('composer_cache_dir') => false,
        );
        foreach ($paths as $path => &$unwritable) {
            $unwritable = is_dir($path) && !is_writable($path);
        }
        if ($paths = array_keys(array_filter($paths))) {
            $output->writeln('The following directories are not writable, make sure you run bin/cron with the web user and then wipe them or make sure they are owned by the web user: ' . implode(', ', $paths));
            return 1;
        }

        // init composer config on first run
        $composerConfig = new JsonFile($this->getContainer()->getParameter('composer_home').'/config.json');
        if (!$composerConfig->exists()) {
            $isVerbose = true;
            $content = array('config' => array());
            $composerConfig->write($content);
        }
        $composerAuthConfig = new JsonFile($this->getContainer()->getParameter('composer_home').'/auth.json');
        if (!$composerAuthConfig->exists()) {
            $isVerbose = true;
            $output->writeln('You need to setup a GitHub OAuth token because Toran makes a lot of requests and will use up the API calls limit if it is unauthenticated');
            $output->writeln('Head to https://github.com/settings/tokens/new to create a token. You need to select the public_repo credentials, and the repo one if you are going to use private repositories from GitHub with Toran.');

            $dialog = $this->getHelperSet()->get('dialog');
            $token = $dialog->ask($output, 'Token: ', null);

            if ($token) {
                $content = array();
                $content['github-oauth'] = array('github.com' => $token);
                $composerAuthConfig->write($content);
            } else {
                file_put_contents($composerAuthConfig->getPath(), '{}');
            }
        }

        $util = $this->getContainer()->get('toran_util');
        if ('expired' === $util->getProductName()) {
            $output->writeln('Your Toran Proxy license expired and package updates now stopped working');

            return 1;
        }

        // another job is still active
        $lock = new LockHandler('toran_cron_' . md5(__FILE__));
        if (!$lock->lock()) {
            if ($isVerbose) {
                $output->writeln('Aborting, lock file (see '.sys_get_temp_dir().'/sf.toran_cron_*.lock) is present, a previous job is still running or may have died unexpectedly');
            }
            return;
        }

        // handle Ctrl+C for unix-like systems
        if (function_exists('pcntl_signal')) {
            declare(ticks=100);
            pcntl_signal(SIGINT, function () use ($lock) {
                $lock->release();
                exit(130);
            });
        }

        ini_set('memory_limit', -1);
        set_time_limit(0);

        $input->setOption('verbose', $isVerbose);
        try {
            $this->syncPrivateRepositories($input, $output);
            $this->syncPublicPackages($input, $output);
        } catch (\Exception $e) {
            $lock->release();
            throw $e;
        }

        $lock->release();

        return 0;
    }

    private function syncPublicPackages(InputInterface $input, OutputInterface $output)
    {
        $toranConfig = $this->getContainer()->get('config');
        if ('proxy' !== $toranConfig->get('packagist_sync')) {
            return;
        }

        $config = Factory::createConfig();
        $io = $this->createIO($input, $output);
        $io->loadConfiguration($config);
        $versionParser = new VersionParser;

        // sort by origin
        $packages = $toranConfig->getSyncedPackages();
        asort($packages);

        if ($toranConfig->get('monorepo')) {
            $privatePackages = array_flip($toranConfig->getPrivatePackageNames());
        }

        foreach ($toranConfig->getPublicRepositories() as $repo) {
            $proxy = $this->getContainer()->get('proxy_factory')->createProxy($repo, $io, $config);

            $io->write("<info>Initializing {$repo['origin']} proxy repository</info>");

            // initialize
            $proxy->getRootFile();

            $distSyncMode = $toranConfig->get('dist_sync_mode');

            foreach ($packages as $package => $origin) {
                // skip packages from another proxy
                if ($origin !== $repo['origin']) {
                    continue;
                }

                // skip already-synced private packages in monorepos
                if (isset($privatePackages[$package])) {
                    continue;
                }

                // skip packages not in whitelist
                if ($whitelist = $input->getArgument('packages')) {
                    if (!in_array($package, $whitelist, true)) {
                        continue;
                    }
                }

                try {
                    $this->syncPublicPackage($io, $config, $proxy, $package, $distSyncMode, $versionParser);
                } catch (\Exception $e) {
                    if ($input->getOption('verbose')) {
                        throw $e;
                    }
                    $io->write('<error>'.get_class($e).' while updating '.$package.': '.$e->getMessage().'</error>', true, IOInterface::QUIET);
                }
            }
        }

        if (isset($proxy)) {
            // clean providers using the last proxy as they all share a single web dir
            $proxy->cleanProviderFiles(array_merge(array_keys($toranConfig->getSyncedPackages()), $toranConfig->getPrivatePackageNames()));
        }

        foreach ($toranConfig->getPublicRepositories() as $repo) {
            if ('packagist.org' !== $repo['origin']) {
                continue;
            }
            $packagistProxy = $this->getContainer()->get('proxy_factory')->createProxy($repo, $io, $config);
            $packagistProxy->writeRootFile();
        }

        // TODO MED clean up old zip dist files here if distSyncMode is not `all`
    }

    private function syncPublicPackage(IOInterface $io, ComposerConfig $config, Proxy $proxy, $package, $distSyncMode, VersionParser $versionParser)
    {
        $io->write("<info>Synchronizing dist archives and clone for $package</info>");

        // getProviderFile already syncs git repos implicitly if the file is new
        $data = $proxy->getProviderFile($package, $io, $config);
        // update file in web dir
        $proxy->storeProviderFile($package, $data);

        if ($distSyncMode !== 'lazy') {
            $data = JsonFile::parseJson($data);
            foreach ($data['packages'] as $name => $versions) {
                if ($name !== $package) {
                    continue;
                }

                $versions = Util::sortByVersion($versions);
                $syncDists = $distSyncMode === 'all';

                foreach ($versions as $versionData) {
                    if ($versionData['type'] === 'metapackage' || empty($versionData['dist']['url'])) {
                        continue;
                    }

                    if (!isset($versionData['version_normalized'])) {
                        $versionData['version_normalized'] = $versionParser->normalize($versionData['version']);
                    }

                    if ($syncDists) {
                        $proxy->downloadPackage($versionData);
                    } elseif ($distSyncMode === 'new' && false === strpos($versionData['version_normalized'], 'dev') && $proxy->hasPackageInCache($versionData)) {
                        // non-dev releases that are synced trigger a sync of all newer releases if distSyncMode is 'new'
                        $syncDists = true;
                    }
                }
            }
        }
    }

    private function syncPrivateRepositories(InputInterface $input, OutputInterface $output)
    {
        $toranConfig = $this->getContainer()->get('config');

        $repoSyncer = $this->getContainer()->get('repo_syncer');
        $io = $this->createIO($input, $output);
        $repos = $toranConfig->getRepositories();

        $repoSyncer->sync($io, $repos, $input->getArgument('packages'));
    }

    private function createIO(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('verbose')) {
            return new ConsoleIO($input, $output, $this->getHelperSet());
        }

        $output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        return new ConsoleIO(new StringInput(''), $output, $this->getHelperSet());
    }
}

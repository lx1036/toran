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
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Util\ComposerMirror;
use Composer\Util\Filesystem;
use Composer\Util\Git as GitUtil;
use Composer\Util\ProcessExecutor;

class SourceSyncer
{
    private $config;
    private $gitCacheDir;
    private $updatedDirs = [];

    public function __construct(Configuration $config)
    {
        $this->config      = $config;
        $this->gitCacheDir = rtrim($config->get('git_path'), '/\\');
    }

    public function sync(IOInterface $io, ComposerConfig $composerConfig, array $packages)
    {
        set_time_limit(0);
        foreach ($packages as $package) {
            if ($package->getType() === 'metapackage') {
                continue;
            }
            if ($package instanceof AliasPackage) {
                $package = $package->getAliasOf();
            }
            $this->syncPackageSource($io, $composerConfig, $package);
        }
    }

    public function removePackage(PackageInterface $package)
    {
        $url     = $package->getSourceUrl();
        $repoDir = null;

        if ($url && $package->getSourceType() === 'git') {
            $repoDir = ComposerMirror::processGitUrl($this->gitCacheDir . Proxy::GIT_CACHE_FORMAT, $package->getName(), $url, 'git');
        }

        if ($repoDir && is_dir($repoDir)) {
            $fs = new Filesystem();
            $fs->removeDirectory($repoDir);
        }
    }

    private function syncPackageSource(IOInterface $io, ComposerConfig $composerConfig, PackageInterface $package)
    {
        $url = $package->getSourceUrl();
        if ($this->config->isGitSyncEnabled() && $url && $package->getSourceType() === 'git') {
            GitUtil::cleanEnv();

            $process = new ProcessExecutor();
            $repoDir = ComposerMirror::processGitUrl($this->gitCacheDir . Proxy::GIT_CACHE_FORMAT, $package->getName(), $url, 'git');
            $fs      = new Filesystem();
            $fs->ensureDirectoryExists(dirname($repoDir));

            if (!is_writable(dirname($repoDir))) {
                $user = '';
                if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
                    $info = posix_getpwuid(posix_geteuid());
                    if (!empty($info['name'])) {
                        $user = ' (' . $info['name'] . ')';
                    }
                }
                throw new \RuntimeException('Can not clone ' . $url . '. The "' . dirname($repoDir) . '" directory is not writable by the current user' . $user . '.');
            }

            if (in_array($repoDir, $this->updatedDirs, true)) {
                // already synced this run, skip it
                return;
            }

            // update the repo if it is a valid git repository
            if (is_dir($repoDir) && 0 === $process->execute('git rev-parse --git-dir', $output, $repoDir) && trim($output) === '.') {
                if (0 !== $process->execute('git remote update --prune origin', $output, $repoDir)) {
                    $io->write('<error>' . $process->getErrorOutput() . '</error>');
                }
            } else {
                // clean up directory and do a fresh clone into it
                $fs->removeDirectory($repoDir);

                $gitUtil         = new GitUtil($io, $composerConfig, $process, $fs);
                $commandCallable = function ($url) use ($repoDir) {
                    return sprintf('git clone --mirror %s %s', escapeshellarg($url), escapeshellarg($repoDir));
                };

                $gitUtil->runCommand($commandCallable, $url, $repoDir, true);
            }

            $this->updatedDirs[] = $repoDir;
        }
        // TODO LOW mirror hg repos
    }
}

<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Finder\Finder;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\AliasPackage;
use Composer\Downloader\FileDownloader;
use Composer\Json\JsonFile;
use Composer\Util\RemoteFilesystem;
use Composer\Util\ProcessExecutor;
use Composer\Util\Filesystem;
use Composer\Package\PackageInterface;
use Composer\Util\ComposerMirror;
use Composer\IO\NullIO;
use Composer\IO\IOInterface;
use Composer\Factory;
use Composer\Semver\VersionParser;
use Composer\Config as ComposerConfig;
use Psr\Log\LoggerInterface;

class Proxy
{
    const CACHE_FORMAT = '/dists/%package%/%version%_%reference%.%type%';
    const GIT_CACHE_FORMAT = '/%package%/%normalizedUrl%.%type%';
    private $config;
    private $webDir;
    private $sourceSyncer;
    private $repoUrl;
    private $repoUrlSchemeHost;
    private $repoUrlId;
    private $repoName;
    private $cacheDir;
    private $rfs;
    private $downloader;
    private $origNotifyUrl;
    private $generator;
    private $logger;
    private $repoIdentifier;

    public function __construct(UrlGeneratorInterface $generator, Configuration $config, SourceSyncer $sourceSyncer, $repoName, $repoUrl, $webDir, $cacheDir, RemoteFilesystem $remoteFilesystem, FileDownloader $downloader, LoggerInterface $logger = null)
    {
        $this->repoUrlId = self::createRepoId($repoUrl);
        $this->generator = $generator;
        $this->config = $config;
        $this->sourceSyncer = $sourceSyncer;
        $this->repoUrl = rtrim($repoUrl, '/');
        $this->repoUrlSchemeHost = preg_replace('{(https?://[^/]+).*}i', '$1', $this->repoUrl);
        $this->repoName = $repoName;
        $this->repoIdentifier = $config->get('monorepo') ? 'all' : 'packagist';
        $this->webDir = rtrim($webDir, '/\\').'/repo/' . $this->repoIdentifier . '/';
        $this->cacheDir = rtrim($cacheDir, '/\\') . '/' . $this->repoUrlId;
        $this->rfs = $remoteFilesystem;
        $this->downloader = $downloader;
        $this->logger = $logger;
    }

    /**
     * Loads the packages.json file
     *
     * - from local cache if it's fresh (<60s)
     * - or from remote if available
     * - or from stale cache with warning
     */
    public function getRootFile()
    {
        $rootCacheFile = $this->cacheDir.'/p/packages.json';
        if (file_exists($rootCacheFile) && filemtime($rootCacheFile) > time() - 60) {
            // TODO LOW rebuild provider-includes from the packages we already have in cache (if they are not stale?)
            return file_get_contents($rootCacheFile);
        }

        $opts = array('http' => array('timeout' => 4));
        $contents = $this->getContents($this->repoUrl.'/packages.json', $opts);
        if ($contents) {
            if (!is_dir($this->cacheDir.'/p')) {
                mkdir($this->cacheDir.'/p', 0777, true);
            }
            if (!is_dir($this->cacheDir.'/raw')) {
                mkdir($this->cacheDir.'/raw', 0777, true);
            }

            $data = JsonFile::parseJson($contents);

            if (empty($data['packages'])) {
                unset($data['packages']);
            } elseif (is_array($data['packages'])) {
                $uid = 0;
                $uidPrefix = $this->repoName.'-';
                foreach ($data['packages'] as $_pkg => $_versions) {
                    foreach ($_versions as $_version => $_versionData) {
                        if (!isset($_versionData['uid'])) {
                            $data['packages'][$_pkg][$_version]['uid'] = $uidPrefix.($uid++);
                        }
                    }
                }
                // override the contents so that the raw/packages.json contains the uids as this is used by getProviderPath later
                $contents = json_encode($data);
            }

            unset($data['providers-includes'], $data['includes'], $data['notify_batch'], $data['notify'], $data['provider-includes']);
            if (!empty($data['search']) && $data['search'][0] === '/') {
                $data['search'] = $this->repoUrl . $data['search'];
            }
            $distUrl = self::generateDistUrl($this->generator, $this->repoIdentifier, '%package%', '%version%', '%reference%', '%type%');
            $mirror = array(
                'dist-url' => $distUrl,
                'preferred' => true, // preferred method of installation, puts it above the default url
            );
            if ($gitPrefix = $this->config->get('git_prefix')) {
                $mirror['git-url'] = rtrim($gitPrefix, '/') . self::GIT_CACHE_FORMAT;
            }

            if (!empty($data['mirrors'])) {
                array_unshift($data['mirrors'], $mirror);
            } else {
                $data['mirrors'] = array($mirror);
            }

            $lazyUrl = $this->generator->generate('toran_proxy_providers', array('repo' => $this->repoIdentifier, 'filename' => 'PACKAGE.json'));
            $data['providers-lazy-url'] = str_replace('PACKAGE', '%package%', $lazyUrl);
            if (isset($data['notify-batch'])) {
                $data['notify-batch'] = 0 === strpos($data['notify-batch'], '/') ? rtrim($this->repoUrl, '/') . $data['notify-batch'] : $data['notify-batch'];
            }

            // TODO LOW build out these files and add support for mixed repos in ComposerRepository
            // $data['providers-url'] = '/p/%package%$%hash%.json';

            file_put_contents($this->cacheDir . '/raw/packages.json', $contents);
            $contents = json_encode($data);

            file_put_contents($rootCacheFile, $contents);

            return $contents;
        }

        if ($contents = @file_get_contents($rootCacheFile)) {
            $data = JsonFile::parseJson($contents, $rootCacheFile);
            $data['warning'] = 'This is an old cached copy, '.$this->repoUrl.' could not be reached';
            return json_encode($data);
        }

        throw new \RuntimeException('Failed to fetch '.$this->repoUrl.'/packages.json and it is not cached yet');
    }

    public function writeRootFile()
    {
        file_put_contents($this->webDir.'packages.json', $this->getRootFile());
    }

    /**
     * Generates a mirrorred dist download URL for the given repository config
     */
    public static function generateDistUrl(UrlGeneratorInterface $generator, $repoName, $package, $version, $ref, $type)
    {
        $distUrl = $generator->generate('toran_proxy_dists', array(
            'repo' => 'REPONAME',
            'name' => 'PACK/AGE',
            'version' => 'VERSION',
            'ref' => 'abcd',
            'type' => 'zip',
        ), UrlGeneratorInterface::ABSOLUTE_URL);
        $distUrl = substr($distUrl, 0, -8).'REF.TYPE';

        $distUrl = str_replace(
            array('REPONAME', 'PACK/AGE', 'VERSION', 'REF', 'TYPE'),
            array($repoName, '%package%', '%version%', '%reference%', '%type%'),
            $distUrl
        );

        return ComposerMirror::processUrl($distUrl, $package, $version, $ref, $type);
    }

    /**
     * Generates a filesystem-compliant unique identifier for a given repository url
     */
    public static function createRepoId($repoUrl)
    {
        $repoUrlId = preg_replace('{/packages\.json$}', '', $repoUrl);
        $repoUrlId = preg_replace('{[^a-z0-9_.-]}i', '-', trim($repoUrlId, '/'));
        if ($repoUrlId === 'https---packagist.org' || $repoUrlId === 'http---packagist.org') {
            $repoUrlId = 'packagist';
        }

        return $repoUrlId;
    }

    /**
     * Creates a dist filename for a given package version and
     * preloads the dist from the original URL if it does not exist in
     * the local cache
     */
    public function getDistFilename($name, $version, $ref, $format, IOInterface $io = null)
    {
        $cacheFile = ComposerMirror::processUrl($this->cacheDir.self::CACHE_FORMAT, $name, $version, $ref, $format);

        if (!file_exists($cacheFile)) {
            list($providerPath, $type) = $this->getProviderPath(preg_replace('{\.json$}', '', $name.'.json'));

            if ($type === 'packages') {
                $packages = $providerPath;
            } else {
                if (!$providerPath) {
                    return '';
                }

                if ($type === 'include') {
                    $providerPath = $this->cacheDir.preg_replace('{^(/?).*?(\$.+)?\.json$}', '/p$1'.$name.'$2.json', $providerPath);
                } else {
                    $providerPath = $this->cacheDir.$providerPath;
                }

                // provider was not loaded yet, try to load it
                if (!file_exists($providerPath)) {
                    $this->getProviderFile(preg_replace('{\.json$}', '', $name.'.json'), $io);
                }

                if (file_exists($providerPath)) {
                    $packages = JsonFile::parseJson(file_get_contents($providerPath), $providerPath);
                }
            }

            if (!empty($packages['packages'][$name])) {
                $parser = new VersionParser;

                foreach ($packages['packages'][$name] as $package) {
                    if (!isset($package['version_normalized'])) {
                        $package['version_normalized'] = $parser->normalize($package['version']);
                    }

                    if ($package['version_normalized'] === $version && isset($package['dist']['url'])) {
                        return $this->downloadPackage($package, $cacheFile);
                    }
                }
            }

            return '';
        }

        return $cacheFile;
    }

    public function downloadPackage(array $packageData, $cacheFile = null)
    {
        if (null === $cacheFile) {
            $cacheFile = $this->getCacheFile($packageData);
        }

        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0777, true);
        }

        if (!file_exists($cacheFile)) {
            $loader = new ArrayLoader();
            $package = $loader->load($packageData);

            $path = $this->downloader->download($package, $this->cacheDir.'/tempDownload');
            rename($path, $cacheFile);
            rmdir($this->cacheDir.'/tempDownload');
            // TODO LOW somehow cache that this has been downloaded somewhere?
        }

        return $cacheFile;
    }

    public function hasPackageInCache(array $packageData)
    {
        return file_exists($this->getCacheFile($packageData));
    }

    /**
     * Retrieves the original provider file (p/acme/foo$hash.json) from the original repo
     *
     * - from cache if possible
     * - or fetches it from origin
     * - or warns the user
     */
    public function getProviderFile($filename, IOInterface $io = null, ComposerConfig $config = null)
    {
        if (false !== strpos($filename, '$')) {
            return false;
        }

        $packageName = preg_replace('{\.json$}', '', $filename);
        list($providerPath, $type) = $this->getProviderPath($packageName);
        if ($type === 'packages') {
            return json_encode(array('packages' => array($filename => $providerPath['packages'][$packageName])));
        }
        if (!$providerPath) {
            return false;
        }

        $origCacheFile = null;
        $cacheFile = $this->cacheDir.$providerPath;
        if ($type === 'include') {
            $origCacheFile = $this->cacheDir.'/raw'.$providerPath;
            $cacheFile = $this->cacheDir.preg_replace('{^(/?).*?(\$.+)?\.json$}', '/p$1'.$packageName.'$2.json', $providerPath);
        }

        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0777, true);
        }

        if ($providerPath[0] === '/') {
            $providerUrl = $this->repoUrlSchemeHost.$providerPath;
        } else {
            $providerUrl = $this->repoUrl.'/'.$providerPath;
        }

        if (file_exists($cacheFile) && trim($contents = file_get_contents($cacheFile))) {
            // skip, contents is primed
        } elseif (
            ($origCacheFile && ($contents = file_get_contents($origCacheFile)))
            || ($contents = $this->getContents($providerUrl))
        ) {
            $data = JsonFile::parseJson($contents, $providerUrl);
            $origNotifyUrl = $this->getOriginalNotifyUrl();

            if ($type === 'include') {
                $data['packages'] = array($packageName => $data['packages'][$packageName]);
            }

            foreach ($data['packages'] as $index => $package) {
                foreach ($package as $version => $dummy) {
                    $data['packages'][$index][$version]['notification-url'] = $origNotifyUrl;
                }
            }

            if ($data && isset($data['packages']) && is_array($data['packages'])) {
                $contents = json_encode($data);
                file_put_contents($cacheFile, $contents);
            }
            // TODO LOW start background dist/source sync job for the packages contained in this file
            // TODO LOW update cached package file once the sync job is complete

            if (null === $io) {
                $io = new NullIO;
            }
            if (null === $config) {
                $config = Factory::createConfig();
                $io->loadConfiguration($config);
            }

            $this->cleanOldFiles($cacheFile);
            $this->sourceSyncer->sync($io, $config, $this->loadPackages($data, $packageName));
        } else {
            if ($io) {
                $io->writeError('<warning>File '.$providerUrl.' could not be fetched</warning>', IOInterface::VERBOSE);
            }
            $contents = json_encode(array(
                'packages' => array(),
                'warning' => 'The original file '.$providerUrl.' could not be fetched',
            ));
        }

        return $contents;
    }

    public function storeProviderFile($filename, $contents)
    {
        $filename = preg_replace('{\.json$}', '', $filename);

        $webFile = $this->webDir.'p/'.$filename.'.json';
        if (!is_dir(dirname($webFile))) {
            mkdir(dirname($webFile), 0777, true);
        }

        file_put_contents($webFile, $contents, LOCK_EX);
    }

    /**
     * Clean up web root from files that are not updated as we don't want a stale package to be fetchable
     *
     * If it is deleted then stale packages will hit ProxyController and re-add the package to the sync queue
     */
    public function cleanProviderFiles(array $syncedPackages)
    {
        if (!is_dir($this->webDir.'p')) {
            return;
        }

        $basePath = strtr(realpath($this->webDir.'p').'/', '\\', '/');
        foreach (Finder::create()->files()->name('*.json')->in($this->webDir.'p') as $file) {
            $path = strtr($file, '\\', '/');
            $pkgName = substr($path, strlen($basePath), -5);
            if (!in_array($pkgName, $syncedPackages, true)) {
                unlink($file);
                if (!glob(dirname($file).'/*')) {
                    @rmdir(dirname($file));
                }
            }
        }
    }

    public function removePackage($packageName)
    {
        // attempt source deletion
        $packageData = JsonFile::parseJson($this->getProviderFile($packageName));
        if (isset($packageData['packages'][$packageName])) {
            $package = $this->loadPackage(current($packageData['packages'][$packageName]));
            $this->sourceSyncer->removePackage($package);
        }

        // clear dist files
        $cacheFileMask = ComposerMirror::processUrl(
            $this->cacheDir.self::CACHE_FORMAT,
            $packageName,
            '*',
            '0000000000000000000000000000000000000000',
            '*'
        );
        $cacheFileMask = str_replace('0000000000000000000000000000000000000000', '*', $cacheFileMask);
        $files = glob($cacheFileMask) ?: array();
        foreach ($files as $file) {
            @unlink($file);
        }

        // remove from config
        $this->config->removeSyncedPackage($packageName);
        $this->config->save();
    }

    private function loadPackages(array $data, $filter = null)
    {
        $packages = array();

        $loader = new ArrayLoader();
        foreach ($data['packages'] as $package => $versions) {
            if ($filter && $filter !== $package) {
                continue;
            }
            foreach ($versions as $version) {
                $packages[] = $loader->load($version);
            }
        }

        return $packages;
    }

    private function loadPackage(array $data, ArrayLoader $loader = null)
    {
        if (!$loader) {
            $loader = new ArrayLoader();
        }

        return $loader->load($data);
    }

    private function getCacheFile(array $packageData)
    {
        if (!isset($packageData['version_normalized'])) {
            $parser = new VersionParser;
            $packageData['version_normalized'] = $parser->normalize($packageData['version']);
        }

        return ComposerMirror::processUrl(
            $this->cacheDir.self::CACHE_FORMAT,
            $packageData['name'],
            $packageData['version_normalized'],
            !empty($packageData['dist']['reference']) ? $packageData['dist']['reference'] : null,
            $packageData['dist']['type']
        );
    }

    private function getProviderPath($package)
    {
        $rootCacheFile = $this->cacheDir . '/raw/packages.json';
        if (!file_exists($rootCacheFile) || filemtime($rootCacheFile) < time() - 600) {
            $this->getRootFile();
        }
        $root = JsonFile::parseJson(file_get_contents($rootCacheFile), $rootCacheFile);

        if (isset($root['provider-includes'])) {
            foreach ($root['provider-includes'] as $url => $meta) {
                $fileName = str_replace('%hash%', $meta['sha256'], $url);
                $url = $this->repoUrl.'/'.$fileName;
                $cacheFile = $this->cacheDir.'/raw/'.basename($url);

                if (file_exists($cacheFile)) {
                    $contents = file_get_contents($cacheFile);
                } elseif ($contents = $this->getContents($url)) {
                    file_put_contents($cacheFile, $contents);
                    $this->cleanOldFiles($cacheFile);
                } else {
                    return array(false, false);
                }

                $data = JsonFile::parseJson($contents);
                if (isset($data['providers'][$package])) {
                    $this->config->addSyncedPackage($package, $this->repoName);
                    $this->config->save();

                    return array(
                        str_replace(
                            array('%package%', '%hash%'),
                            array($package, $data['providers'][$package]['sha256']),
                            $root['providers-url']
                        ),
                        'provider'
                    );
                }
            }
        }

        if (isset($root['includes'])) {
            foreach ($root['includes'] as $url => $meta) {
                $fileName = str_replace('%hash%', $meta['sha1'], $url);
                $url = $this->repoUrl.'/'.$fileName;
                $cacheFile = $this->cacheDir.'/raw/'.$fileName;

                if (file_exists($cacheFile)) {
                    $contents = file_get_contents($cacheFile);
                } elseif ($contents = $this->getContents($url)) {
                    if (!is_dir(dirname($cacheFile))) {
                        mkdir(dirname($cacheFile), 0777, true);
                    }

                    // add UIDs if they are missing (typical in satis repos)
                    $data = JsonFile::parseJson($contents);
                    $uid = 0;
                    $uidPrefix = $this->repoName.'-';
                    foreach ($data['packages'] as $_pkg => $_versions) {
                        foreach ($_versions as $_version => $_versionData) {
                            if (!isset($_versionData['uid'])) {
                                $data['packages'][$_pkg][$_version]['uid'] = $uidPrefix.($uid++);
                            }
                        }
                    }
                    $contents = json_encode($data);

                    file_put_contents($cacheFile, $contents);
                    $this->cleanOldFiles($cacheFile);
                } else {
                    return array(false, false);
                }

                $data = JsonFile::parseJson($contents);
                if (isset($data['packages'][$package])) {
                    $this->config->addSyncedPackage($package, $this->repoName);
                    $this->config->save();

                    return array('/'.$fileName, 'include');
                }
            }
        }

        if (isset($root['packages'])) {
            if (isset($root['packages'][$package])) {
                $this->config->addSyncedPackage($package, $this->repoName);
                $this->config->save();

                return array($root, 'packages');
            }
        }

        return array(false, false);
    }

    private function cleanOldFiles($path)
    {
        // clean up old files
        $files = Finder::create()->files()->ignoreVCS(true)
            ->name('/'.preg_replace('{\$.*}', '', basename($path)).'\$[a-f0-9]+\.json$/')
            ->date('until 10minutes ago')
            ->in(dirname((string) $path));
        foreach ($files as $file) {
            unlink((string) $file);
        }
    }

    private function getOriginalNotifyUrl()
    {
        if (!$this->origNotifyUrl && function_exists('apc_fetch')) {
            $this->origNotifyUrl = \apc_fetch('notify_url_'.md5($this->repoUrl));
        }
        if (!$this->origNotifyUrl) {
            $contents = $this->getRootFile();
            if (!$contents) {
                throw new \RuntimeException('Could not load data from '.$this->repoUrl);
            }
            $root = JsonFile::parseJson($contents);
            if (isset($root['notify-batch'])) {
                if ('/' === $root['notify-batch'][0]) {
                    $this->origNotifyUrl = $this->repoUrlSchemeHost . $root['notify-batch'];
                } else {
                    $this->origNotifyUrl = $root['notify-batch'];
                }
            } else {
                $this->origNotifyUrl = '';
            }
            if (function_exists('apc_store')) {
                \apc_store('notify_url_'.md5($this->repoUrl), $this->origNotifyUrl, 86400);
            }
        }

        return $this->origNotifyUrl === '' ? false : $this->origNotifyUrl;
    }

    private function getContents($url, array $opts = array(), $suppressFailures = true)
    {
        $retries = 3;
        while ($retries--) {
            try {
                $host = parse_url($url, PHP_URL_HOST);
                if (preg_match('{\.github\.com$}i', $host)) {
                    $host = 'github.com';
                }

                $contents = $this->rfs->getContents($host, $url, false, $opts);
                // if the JSON is invalid then retry fetching 3 times just in case the connection is very flakey
                if (substr($url, -5) === '.json' && null === @json_decode($contents, true)) {
                    if (!$retries) {
                        return $contents;
                    }

                    continue;
                }

                return $contents;
            } catch (\Exception $e) {
                if ($this->logger) {
                    $this->logger->error('Failed to download '.$url.': '.$e->getMessage());
                }

                if ($suppressFailures) {
                    return;
                }

                throw $e;
            }
        }
    }
}

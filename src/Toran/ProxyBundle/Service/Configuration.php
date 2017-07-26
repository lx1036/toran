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

use Symfony\Component\Yaml\Yaml;
use Toran\ProxyBundle\Model\Repository;

class Configuration
{
    private $file;
    private $config = [];
    private $dirty  = [];

    public function __construct($file, $cacheDir)
    {
        $this->config = $this->loadConfig($file, $cacheDir);

        $this->file = $file;
    }

    public function getPublicRepositories()
    {
        $repos = array_filter($this->config['public_repositories'], function ($repo) {
            return !empty($repo['url']);
        });
        if ($this->config['packagist_sync'] === 'proxy') {
            $repos[] = ['url' => 'https://packagist.org', 'origin' => 'packagist.org'];
        }

        return $repos;
    }

    public function setPublicRepositories($repositories)
    {
        $repos = [];
        foreach (preg_split('{(\r?\n)+}', trim($repositories)) as $repo) {
            if (!$repo) {
                continue;
            }

            $repos[] = [
                'url'    => str_replace('/packages.json', '', $repo),
                'origin' => parse_url($repo, PHP_URL_HOST),
            ];
        }

        if (!isset($this->config['public_repositories']) || $repos !== $this->config['public_repositories']) {
            $this->config['public_repositories'] = $repos;
            $this->dirty[]                       = 'public_repositories';
        }
    }

    public function getRepositories()
    {
        return $this->config['repositories'];
    }

    public function getRepository($id, $digest)
    {
        if (isset($this->config['repositories'][$id]) && $this->config['repositories'][$id]->getDigest() === $digest) {
            return $this->config['repositories'][$id];
        }

        throw new \LogicException('Requested repository id ' . $id . ' with invalid digest ' . $digest);
    }

    public function addRepository(Repository $newRepo)
    {
        foreach ($this->config['repositories'] as $repo) {
            if ($repo->getDigest() === $newRepo->getDigest()) {
                return $repo;
            }
        }

        $this->config['repositories'][] = $newRepo;
        $this->reindexRepositories();
        $this->dirty[] = 'repositories';

        return $newRepo;
    }

    public function removeRepository(Repository $repo)
    {
        if (false !== ($idx = array_search($repo, $this->config['repositories'], true))) {
            unset($this->config['repositories'][$idx]);
            $this->reindexRepositories();
            $this->dirty[] = 'repositories';
        }
    }

    public function setRepositoryPackageNames(Repository $repo, array $names)
    {
        if (!isset($repo->config['toran_package_names']) || $repo->config['toran_package_names'] !== $names) {
            $repo->config['toran_package_names'] = $names;
            $this->dirty[]                       = 'repositories';
        }
    }

    public function updateRepositoryConfig(Repository $repo, array $config)
    {
        if ($repo->config !== $config) {
            $repo->config  = $config;
            $this->dirty[] = 'repositories';
        }
    }

    public function isGitSyncEnabled()
    {
        return $this->get('git_prefix') && $this->get('git_path');
    }

    public function get($key)
    {
        $res = isset($this->config[$key]) ? $this->config[$key] : false;

        switch ($key) {
            case 'git_prefix':
                // make sure it ends with a path delimiter (also : to allow prefixes like git@foo.bar:)
                if ($res && !preg_match('{[\\/:]$}', $res)) {
                    $res .= '/';
                }
                break;
            case 'dist_sync_mode':
                if (false === $res) {
                    $res = 'lazy';
                }
                break;
        }

        return $res;
    }

    public function set($key, $val)
    {
        if (!isset($this->config[$key]) || $this->config[$key] !== $val) {
            $this->config[$key] = $val;
            $this->dirty[]      = $key;
        }
    }

    public function addSyncedPackage($package, $origin)
    {
        if (!isset($this->config['public_packages'][$package]) || $this->config['public_packages'][$package] !== $origin) {
            $this->config['public_packages'][$package] = $origin;
            $this->dirty[]                             = 'public_packages';
        }
    }

    public function removeSyncedPackage($package)
    {
        if (isset($this->config['public_packages'][$package])) {
            unset($this->config['public_packages'][$package]);
            $this->dirty[] = 'public_packages';
        }
    }

    public function getPrivatePackageNames()
    {
        $names = [];
        foreach ($this->getRepositories() as $repo) {
            foreach ($repo->getNames() as $name) {
                $names[strtolower($name)] = true;
            }
        }

        return array_keys($names);
    }

    public function getSyncedPackages()
    {
        return $this->config['public_packages'];
    }

    public function save()
    {
        if (!$this->dirty) {
            return;
        }

        // reload from disk and copy over dirty keys to avoid/minimize races
        $configToSave = $this->loadConfig($this->file);
        unset($configToSave['cache-hash']);
        foreach ($this->dirty as $key) {
            $configToSave[$key] = $this->config[$key];
        }

        // save new config
        foreach ($configToSave['repositories'] as $idx => $repo) {
            $configToSave['repositories'][$idx] = $repo->config;
        }

        if (!file_put_contents($this->file, Yaml::dump($configToSave))) {
            if (!is_writable($this->file)) {
                throw new \RuntimeException('Unable to write the config into ' . $this->file . ' (permissions are incorrect)');
            }
            throw new \RuntimeException('Unable to write the config into ' . $this->file);
        }

        $this->dirty = [];
    }

    private function loadConfig($file, $cacheDir = null)
    {
        $config = [];
        if ($cacheDir !== null) {
            $configCachePath = $cacheDir . '/toran-config-cache.php';
            if (file_exists($configCachePath)) {
                $config = include $configCachePath;
            }
        }

        //        var_dump($file);

        if (file_exists($file) && (empty($config['cache-hash']) || hash_file('sha1', $file) !== $config['cache-hash'])) {
            $config = Yaml::parse(file_get_contents($file));
            if ($cacheDir !== null) {
                $config['cache-hash'] = hash_file('sha1', $file);
                file_put_contents($configCachePath, '<?php return ' . var_export($config, true) . ';');
            }
        }

        if (!empty($config['repositories'])) {
            foreach ($config['repositories'] as $idx => $repo) {
                $config['repositories'][$idx] = Repository::fromArray($repo, $idx);
            }
        } else {
            $config['repositories'] = [];
        }
        if (!isset($config['public_packages'])) {
            $config['public_packages'] = [];
        }
        if (!isset($config['public_repositories'])) {
            $config['public_repositories'] = [];
        }

        if (!empty($config['packagist_packages'])) {
            foreach ($config['packagist_packages'] as $package) {
                $config['public_packages'][$package] = 'packagist.org';
            }
            unset($config['packagist_packages']);
        }

        return $config;
    }

    private function reindexRepositories()
    {
        $this->config['repositories'] = array_values($this->config['repositories']);
        foreach ($this->config['repositories'] as $idx => $repo) {
            $repo->id = $idx;
        }
    }
}

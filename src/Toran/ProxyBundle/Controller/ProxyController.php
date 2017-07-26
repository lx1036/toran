<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Composer\Downloader\FileDownloader;
use Composer\Util\RemoteFilesystem;
use Composer\IO\NullIO;
use Composer\IO\BufferIO;
use Composer\Factory;
use Composer\Semver\VersionParser;
use Toran\ProxyBundle\Service\Proxy;

class ProxyController extends Controller
{
    public function rootAction(Request $req, $repo)
    {
        if ($repo == 'all') {
            return new Response('The monorepo was not initialized, you should make sure you are running bin/cron regularly', 404);
        }

        if ($repo == 'private') {
            return $this->getUninitializedPrivateRepo();
        }

        try {
            foreach ($this->getPublicProxies('packagist.org') as $proxy) {
                return new Response($proxy->getRootFile(), 200, array('Content-Type' => 'application/json'));
            }
        } catch (HttpException $e) {
            return new Response($e->getMessage(), $e->getStatusCode());
        } catch (\Exception $e) {
            return new Response($e->getMessage(), 500);
        }
    }

    public function distAction(Request $req, $repo, $name, $version, $ref, $type)
    {
        $cacheFile = null;
        if ($repo == 'all' && in_array(strtolower($name), $this->get('config')->getPrivatePackageNames())) {
            $repo = 'private';
        }

        if ($repo == 'private') {
            $cacheFile = $this->get('repo_syncer')->getDistFilename($req, $name, $version, $ref, $type);
        } else {
            $filter = 'unknown';
            $packages = $this->get('config')->getSyncedPackages();
            if (!empty($packages[$name])) {
                $filter = $packages[$name];
            }
            $io = new BufferIO;
            foreach ($this->getPublicProxies($filter) as $proxy) {
                if ($cacheFile = $proxy->getDistFilename($name, $version, $ref, $type, $io)) {
                    break;
                }
            }

            // try to auto-initialize the package if it was not tracked
            if (null === $cacheFile) {
                foreach ($this->getPublicProxies() as $proxy) {
                    if ($contents = $proxy->getProviderFile($name, $io)) {
                        $cacheFile = $proxy->getDistFilename($name, $version, $ref, $type, $io);
                        break;
                    }
                }
            }
        }

        if (null === $cacheFile) {
            return new Response('This package is not in your list of synced packages' . "\n\n" . $io->getOutput(), 404);
        }

        if ('' === $cacheFile) {
            return new Response('This dist file can not be found nor downloaded from the original url' . "\n\n" . $io->getOutput(), 404);
        }

        return new BinaryFileResponse($cacheFile, 200, array(), false);
    }

    public function providerAction($repo, $filename)
    {
        if ($repo == 'all' && in_array(strtolower(preg_replace('{\.json$}', '', $filename)), $this->get('config')->getPrivatePackageNames())) {
            $repo = 'private';
        }

        if ($repo == 'private') {
            return $this->getUninitializedPrivateRepo();
        }

        $io = new BufferIO;
        foreach ($this->getPublicProxies() as $proxy) {
            if ($contents = $proxy->getProviderFile($filename, $io)) {
                break;
            }
        }

        if ($output = $io->getOutput()) {
            $this->get('logger')->error('Failure while syncing '.$filename.': '.$output);
        }

        if (false === $contents) {
            $data = array(
                'packages' => array(),
                'warning' => 'The original providers could not be fetched',
            );

            return new JsonResponse($data);
            // TODO LOW would be good to just return 404, supported by new composer but not old ones
            return new Response('Not Found', 404);
        }

        $proxy->storeProviderFile($filename, $contents);

        return new Response($contents, 200, array('Content-Type' => 'application/json'));
    }

    public function trackDownloadsAction(Request $req, $repo)
    {
        $config = $this->get('config');
        if ($repo !== 'private' || !$config->get('track_downloads')) {
            return new Response('Not Found', 404);
        }

        $postData = json_decode($req->getContent(), true);

        if (empty($postData['downloads']) || !is_array($postData['downloads'])) {
            return new Response('Malformed request', 400);
        }

        $parser = new VersionParser;
        $repoPath = $this->container->getParameter('toran_web_dir') . '/repo/private/';
        foreach ($postData['downloads'] as $package) {
            $provider = $repoPath . '/p/' . $package['name'] . '.json';
            if (file_exists($provider)) {
                $providerData = json_decode(file_get_contents($provider), true);
                if (empty($providerData['packages'][$package['name']])) {
                    continue;
                }

                foreach ($providerData['packages'][$package['name']] as $version) {
                    if (!isset($version['version_normalized'])) {
                        $version['version_normalized'] = $parser->normalize($version['version']);
                    }

                    if ($version['version_normalized'] === $package['version']) {
                        $package['version'] = $version['version'];
                        break;
                    }
                }

                $logFile = $this->container->getParameter('kernel.logs_dir').'/downloads.'.$repo.'.log';
                file_put_contents($logFile, date('[Y-m-d H:i:s]') . ' ' . $req->getClientIp() . ' ' . $package['name'] . ' '. $package['version'].PHP_EOL, FILE_APPEND);
            }
        }

        return new JsonResponse(array('status' => 'success'), 201);
    }

    protected function getUninitializedPrivateRepo()
    {
        $webDir = realpath($this->container->getParameter('toran_web_dir'));
        if (!file_exists($webDir.'/repo/private/packages.json')) {
            return new Response('This repository was not initialized, you should make sure you are running bin/cron regularly', 404);
        }

        $path = $this->get('request_stack')->getCurrentRequest()->getPathInfo();
        if (file_exists($webDir.$path)) {
            return new Response(file_get_contents($webDir.$path), 200, array('Content-Type' => 'application/json'));
        }

        return new Response('This repository is missing the file you are looking for', 404);
    }

    protected function getPublicProxies($originFilter = null)
    {
        if ('proxy' !== $this->get('config')->get('packagist_sync')) {
            throw new NotFoundHttpException('This repository does not have public proxying enabled');
        }

        $config = Factory::createConfig();
        $io = new NullIO;
        $io->loadConfiguration($config);

        $proxies = array();
        foreach ($this->get('config')->getPublicRepositories() as $repo) {
            if ($originFilter && $originFilter !== $repo['origin']) {
                continue;
            }
            $proxies[] = $this->get('proxy_factory')->createProxy($repo, $io, $config);
        }

        return $proxies;
    }
}

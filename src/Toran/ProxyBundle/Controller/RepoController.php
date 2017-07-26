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

namespace Toran\ProxyBundle\Controller;

use Composer\Config as ComposerConfig;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Semver\Comparator;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Toran\ProxyBundle\Model\Repository;

class RepoController extends Controller
{
    const GITHUB_URL_RE    = '{^(?:https?://|git://|ssh://)?(?:[a-zA-Z0-9_\-]+@)?(?P<host>[a-z0-9.-]+)(?::(?:\d+/)?|/)(?P<path>[\w.\-/]+?)(?:\.git|/)?$}';
    const BITBUCKET_URL_RE = '{^(?:https?://|git://|git@)?(?:api\.)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
    const LOCAL_URL_RE     = '{^(?:file://)?(?P<host>)(?P<path>.+)$}';

    public function indexAction()
    {
        //        var_dump(get_class($this->get('config')));
        $repos = $this->get('config')->getRepositories();

        $uninitializedRepos = array_filter($repos, function ($repo) {
            return !isset($repo->config['toran_package_names']);
        });

        $this->initializeRepos($uninitializedRepos);

        return $this->render('ToranProxyBundle:Repo:index.html.twig', [
            'repos' => $repos,
        ]);
    }

    public function createAction(Request $req)
    {
        $isJsonReq = $req->headers->get('Content-Type') === 'application/json';
        $config    = $this->get('config');
        $repo      = new Repository();
        $builder   = $this->createRepoForm($req, null, $isJsonReq ? ['csrf_protection' => false] : []);
        $builder->add('Create', 'Symfony\Component\Form\Extension\Core\Type\SubmitType');
        $form = $builder->getForm();

        if ($req->getMethod() === 'POST') {
            if ($isJsonReq) {
                $form->submit(JsonFile::parseJson($req->getContent()));
            } else {
                $form->handleRequest($req);
            }

            if ($repo = $this->processForm($form, $repo, $config)) {
                $repoUrl = $this->generateUrl('toran_proxy_repo_view', ['id' => $repo->id, 'digest' => $repo->getDigest()], UrlGeneratorInterface::ABSOLUTE_URL);

                if ($isJsonReq) {
                    return new JsonResponse(
                        ['status' => 'success', 'message' => 'Package created', 'location' => $repoUrl],
                        201,
                        ['Location' => $repoUrl]
                    );
                }

                return $this->redirect($repoUrl);
            }
        }

        return $this->render('ToranProxyBundle:Repo:create.html.twig', ['form' => $form->createView()]);
    }

    public function editAction(Request $req, $id, $digest)
    {
        $config  = $this->get('config');
        $repo    = $config->getRepository($id, $digest);
        $builder = $this->createRepoForm($req, $repo);
        $builder->add('Save', 'Symfony\Component\Form\Extension\Core\Type\SubmitType');
        $form = $builder->getForm();

        if ($req->getMethod() === 'POST') {
            $form->handleRequest($req);
            if ($repo = $this->processForm($form, $repo, $config)) {
                return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
            }
        }

        return $this->render('ToranProxyBundle:Repo:edit.html.twig', ['form' => $form->createView()]);
    }

    public function updateAction(Request $req, $id, $digest)
    {
        $config = $this->get('config');
        try {
            $repo = $config->getRepository($id, $digest);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => 'Repository / package not found',
                'details' => 'It seems you used an outdated page, try to go back to the list of private repositories',
            ], 400);
        }

        return $this->runUpdate($repo, (bool) $req->query->get('showOutput'));
    }

    public function viewAction(Request $req, $id, $digest)
    {
        $config = $this->get('config');
        try {
            $repo = $config->getRepository($id, $digest);
        } catch (\Exception $e) {
            return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
        }

        $packages   = [];
        $loader     = new ArrayLoader();
        $repoSyncer = $this->get('repo_syncer');

        if (!$repo->getNames()) {
            return $this->render('ToranProxyBundle:Repo:view.html.twig', ['repo' => $repo, 'packages' => [], 'error' => 'no_data']);
        }

        foreach ($repo->getNames() as $name) {
            $json = $repoSyncer->getJsonMetadataPath($name);
            if (!file_exists($json)) {
                return $this->render('ToranProxyBundle:Repo:view.html.twig', ['repo' => $repo, 'packages' => [], 'error' => 'no_data']);
            }

            $packageData = JsonFile::parseJson(file_get_contents($json), $json);
            if (!empty($packageData['packages'][$name])) {
                foreach ($packageData['packages'][$name] as $package) {
                    $package = $loader->load($package);
                    if ($package instanceof AliasPackage) {
                        $package = $package->getAliasOf();
                    }
                    $packages[$package->getVersion()] = $package;
                }
            }
        }

        uksort($packages, function ($a, $b) {
            if ($a === $b) {
                return 0;
            }

            if (Comparator::lessThan($a, $b)) {
                return 1;
            }

            return -1;
        });

        return $this->render('ToranProxyBundle:Repo:view.html.twig', ['repo' => $repo, 'packages' => $packages]);
    }

    public function deleteAction(Request $req, $id, $digest)
    {
        $config = $this->get('config');

        try {
            $repo = $config->getRepository($id, $digest);
        } catch (\Exception $e) {
            return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
        }

        $config->removeRepository($repo);
        $config->save();

        return $this->redirect($this->generateUrl('toran_proxy_repo_index'));
    }

    public function hookAction(Request $req)
    {
        $util = $this->get('toran_util');
        if ('expired' === $util->getProductName()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Your Toran Proxy license expired and package updates now stopped working'], 500);
        }

        $payload = JsonFile::parseJson($req->request->get('payload'));
        if (!$payload && $req->headers->get('Content-Type') === 'application/json') {
            $payload = JsonFile::parseJson($req->getContent());
        }

        if (!$payload) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload parameter'], 406);
        }

        if (isset($payload['repository']['url']) && preg_match(self::GITHUB_URL_RE, $payload['repository']['url'])) { // github/gitlab/anything hook
            $urlRegex = self::GITHUB_URL_RE;
            $url      = $payload['repository']['url'];
            $url      = str_replace('https://api.github.com/repos', 'https://github.com', $url);
        } elseif (isset($payload['repository']['url'])) { // local url hook
            $urlRegex = self::LOCAL_URL_RE;
            $url      = $payload['repository']['url'];
        } elseif (isset($payload['repository']['links']['html']['href'])) { // new bitbucket hook
            $urlRegex = self::BITBUCKET_URL_RE;
            $url      = $payload['repository']['links']['html']['href'];
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket hook
            $urlRegex = self::BITBUCKET_URL_RE;
            $url      = $payload['canon_url'] . $payload['repository']['absolute_url'];
        } else {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid payload'], 406);
        }

        if (!preg_match($urlRegex, $url)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Could not parse repository URL in payload'], 406);
        }

        // try to find the user package
        $repository = $this->findRepositoryByUrl($url, $urlRegex);

        if (!$repository) {
            return new JsonResponse(['status' => 'error', 'message' => 'Could not find a repository that matches this request'], 404);
        }

        set_time_limit(3600);

        return $this->runUpdate($repository);
    }

    public function render($view, array $parameters = [], Response $response = null)
    {
        if (!isset($parameters['page'])) {
            $parameters['page'] = 'private';
        }

        return parent::render($view, $parameters, $response);
    }

    /**
     * Find a repository given its full URL
     *
     * @param string $url
     * @param string $urlRegex
     * @return Repository|null the found repository or null otherwise
     */
    protected function findRepositoryByUrl($url, $urlRegex)
    {
        if (!preg_match($urlRegex, $url, $matched)) {
            return null;
        }

        $config = $this->get('config');
        foreach ($config->getRepositories() as $repo) {
            if (preg_match($urlRegex, $repo->url, $candidate)
                && strtolower($candidate['host']) === strtolower($matched['host'])
                && strtolower($candidate['path']) === strtolower($matched['path'])
            ) {
                return $repo;
            }
        }

        return null;
    }

    protected function createRepoForm(Request $req, Repository $repo = null, $options = [])
    {
        $repo = $repo ?: new Repository();
        $data = [
            'type'    => $repo->type,
            'url'     => $repo->url,
            'package' => isset($repo->config['package']) ? JsonFile::encode($repo->config['package']) : '',
        ];

        $form = $this->createFormBuilder($data, $options)
            ->add('type', 'Symfony\Component\Form\Extension\Core\Type\ChoiceType', [
                'required'          => true,
                'label'             => 'Type (Use VCS for github/bitbucket/git/svn/hg repositories unless you have a good reason not to)',
                'choices'           => ['vcs' => 'vcs', 'git' => 'git', 'hg' => 'hg', 'svn' => 'svn', 'artifact' => 'artifact', 'pear' => 'pear', 'package' => 'package'],
                'choices_as_values' => true,
            ])
            ->add('url', 'Symfony\Component\Form\Extension\Core\Type\TextType', [
                'required' => false,
                'label'    => 'Repository URL or path (bitbucket git repositories need the trailing .git)',
            ])
            ->add('package', 'Symfony\Component\Form\Extension\Core\Type\TextareaType', [
                'required' => false,
                'label'    => 'JSON package definition (package repositories only)',
            ]);

        return $form;
    }

    protected function processForm($form, $repo, $config)
    {
        if (!count($form->getErrors())) {
            $data = $form->getData();
            if ($data['type'] === 'package') {
                unset($data['url']);
                try {
                    $data['package'] = JsonFile::parseJson($data['package'], 'package');
                } catch (\Exception $e) {
                    $form->get('package')->addError(new FormError('<pre>' . $e->getMessage() . '</pre>'));

                    return false;
                }
            } else {
                unset($data['package']);
            }

            unset($data['toran_package_names']);

            if (null === $repo->id) {
                $repo->config = $data;
                $repo         = $config->addRepository($repo);
            } else {
                $config->updateRepositoryConfig($repo, $data);
            }
            $config->save();

            return $repo;
        }
    }

    private function runUpdate($repository, $forceReturnOutput = false)
    {
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);

        try {
            $repoSyncer = $this->get('repo_syncer');
            $repoSyncer->sync($io, $this->get('config')->getRepositories(), [], $repository);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status'  => 'error',
                'message' => '[' . get_class($e) . '] ' . $e->getMessage(),
                'details' => '<pre>' . $io->getOutput() . '</pre>',
            ], 400);
        }

        if ($forceReturnOutput) {
            return new JsonResponse([
                'status'  => 'success',
                'message' => 'Update successful',
                'details' => '<pre>' . $io->getOutput() . '</pre>',
            ], 202);
        }

        return new JsonResponse(['status' => 'success'], 202);
    }

    private function initializeRepos(array $repos)
    {
        if (!$repos) {
            return;
        }

        $repos = array_values($repos);

        unset(ComposerConfig::$defaultRepositories['packagist']);
        $config = Factory::createConfig();
        $config->merge(['repositories' => array_map(
            function ($r) { return $r->config; },
            $repos
        )]);
        $io = new BufferIO();
        $io->loadConfiguration($config);

        $composerRepos = Factory::createDefaultRepositories($io, $config);
        $providers     = [];

        $index = 0;
        foreach ($composerRepos as $url => $repo) {
            try {
                $packages = $repo->getPackages();
            } catch (\Exception $e) {
                continue;
            }

            $names = [];
            foreach ($packages as $package) {
                if (!isset($names[$package->getName()])) {
                    $names[$package->getName()] = $package->getPrettyName();
                }
            }

            $this->get('config')->setRepositoryPackageNames($repos[$index], array_values($names));
            $this->get('config')->save();

            $index++;
        }
    }
}

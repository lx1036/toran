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

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PackagistController extends Controller
{
    public function indexAction()
    {
        $syncedPackages = $this->get('config')->getSyncedPackages();
        ksort($syncedPackages);

        return $this->render('ToranProxyBundle:Packagist:index.html.twig', [
            'packages'   => $syncedPackages,
            'is_enabled' => 'proxy' === $this->get('config')->get('packagist_sync'),
        ]);
    }

    public function addAction(Request $req)
    {
        $data    = ['packages' => '', 'origin' => 'packagist.org'];
        $origins = [];
        foreach ($this->get('config')->getPublicRepositories() as $repo) {
            $origins[$repo['origin']] = $repo['origin'];
        }

        $form = $this->createFormBuilder($data)
            ->add('packages', 'Symfony\Component\Form\Extension\Core\Type\TextareaType', [
                'required' => false,
                'label'    => 'Packages to start synchronizing (one per line)',
            ])
            ->add('origin', 'Symfony\Component\Form\Extension\Core\Type\ChoiceType', [
                'required'          => true,
                'choices'           => $origins,
                'choices_as_values' => true,
                'label'             => 'Which repository do you want to sync those packages from?',
            ])
            ->add('Add', 'Symfony\Component\Form\Extension\Core\Type\SubmitType')
            ->getForm();

        if ($req->getMethod() === 'POST') {
            $form->handleRequest($req);
            $data = $form->getData();
            if ($form->isValid()) {
                $skipped = [];
                if (trim($data['packages'])) {
                    $io             = new NullIO();
                    $composerConfig = Factory::createConfig();
                    $io->loadConfiguration($composerConfig);
                    $rfs = Factory::createRemoteFilesystem($io, $composerConfig);

                    $config = $this->get('config');
                    $repos  = $config->getPublicRepositories();
                    foreach ($repos as $repo) {
                        if ($repo['origin'] === $data['origin']) {
                            break;
                        }
                    }

                    if ($repo['origin'] === 'packagist.org') {
                        $listUrl       = 'https://packagist.org/packages/list.json';
                        $validPackages = JsonFile::parseJson($rfs->getContents('packagist.org', $listUrl, false), $listUrl);
                        $verifyPackage = function ($package) use ($validPackages) {
                            return in_array($package, $validPackages['packageNames'], true);
                        };
                    } else {
                        $proxy         = $this->get('proxy_factory')->createProxy($repo, $io, $composerConfig);
                        $verifyPackage = function ($package) use ($proxy) {
                            return false !== $proxy->getProviderFile($package);
                        };
                    }

                    foreach (preg_split('{(\r?\n)+}', trim($data['packages'])) as $package) {
                        if ($verifyPackage($package)) {
                            $config->addSyncedPackage($package, $data['origin']);
                        } else {
                            $skipped[] = $package;
                        }
                    }
                    $config->save();
                }

                if ($skipped) {
                    $this->get('session')->getFlashBag()->add('warning', 'The following packages were not found and were skipped: ' . implode(', ', $skipped));
                } else {
                    $this->get('session')->getFlashBag()->add('success', 'Packages added successfully');
                }

                return $this->redirect($this->generateUrl('toran_proxy_packagist_index'));
            }
        }

        return $this->render('ToranProxyBundle:Packagist:add.html.twig', ['form' => $form->createView()]);
    }

    public function deleteAction(Request $req, $package)
    {
        $repos    = $this->get('config')->getPublicRepositories();
        $packages = $this->get('config')->getSyncedPackages();

        foreach ($repos as $repo) {
            if ($packages[$package] === $repo['origin']) {
                $proxy = $this->get('proxy_factory')->createProxy($repo);
                $proxy->removePackage($package);
                break;
            }
        }

        $this->get('session')->getFlashBag()->add('success', $package . ' successfully removed');

        return $this->redirect($this->generateUrl('toran_proxy_packagist_index'));
    }

    public function render($view, array $parameters = [], Response $response = null)
    {
        if (!isset($parameters['page'])) {
            $parameters['page'] = 'packagist';
        }

        return parent::render($view, $parameters, $response);
    }
}

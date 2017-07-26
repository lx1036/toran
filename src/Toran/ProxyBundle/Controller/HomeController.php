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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\FormError;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Toran\ProxyBundle\Model\Repository;
use Toran\ProxyBundle\Exception\LicenseExpiredException;
use Composer\Json\JsonFile;

class HomeController extends Controller
{
    public function indexAction(Request $req)
    {
        if (!file_exists($this->container->getParameter('toran_config_file'))) {
            return $this->redirect($this->generateUrl('setup'));
        }

//        var_dump($this->container->getParameter('toran_config_file'));

        return $this->render('ToranProxyBundle:Home:index.html.twig', array('page' => 'home', 'monorepo' => $this->get('config')->get('monorepo')));
    }

    public function setupAction(Request $req)
    {
        $builder = $this->createConfigForm($req);
        $builder->add('Install', 'Symfony\Component\Form\Extension\Core\Type\SubmitType');

        $form = $builder->getForm();

        if ($req->getMethod() === 'POST') {
            $form->handleRequest($req);

            $data = $form->getData();
            $config = $this->get('config');

            $satis = array();
            if (!empty($data['satis_conf'])) {
                list($satis, $err) = $this->decodeJson($data['satis_conf']);
                if ($err) {
                    $form->addError(new FormError($err));
                }
            }

            if (!count($form->getErrors())) {
                $this->validateLicense($form);
                $this->processConfigForm($form, $config);
            }

            if (!count($form->getErrors())) {
                if (isset($satis['repositories'])) {
                    foreach ($satis['repositories'] as $repo) {
                        $config->addRepository(Repository::fromArray($repo, 0));
                    }
                }

                try {
                    $config->save();

                    return $this->redirect($this->generateUrl('post_install'));
                } catch (\Exception $e) {
                    $form->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->render('ToranProxyBundle:Home:install.html.twig', array('form' => $form->createView()));
    }

    public function postInstallAction()
    {
        return $this->render('ToranProxyBundle:Home:post_install.html.twig', array(
            'base_path' => dirname(realpath($this->container->getParameter('kernel.root_dir')))
        ));
    }

    public function docsAction($page)
    {
        $parsedown = new \Parsedown();
        $docDir = $this->container->getParameter('kernel.root_dir').'/../doc/';

        if ($page === 'faq') {
            $faqs = glob($docDir.'faq/*.md');
            $contents = '';
            foreach ($faqs as $faq) {
                $contents .= "\n\n".file_get_contents($faq);
            }
        } else {
            $file = $docDir.$page;
            if (!file_exists($file)) {
                throw new NotFoundHttpException();
            }
            $contents = file_get_contents($file);
        }

        $contents = preg_replace('{^#}m', '##', $contents);
        $contents = str_replace('http://toran.example.com/', $this->generateUrl('home', array(), UrlGeneratorInterface::ABSOLUTE_URL), $contents);
        $contents = $parsedown->text($contents);

        if ($page === 'faq') {
            $toc = array();
            $contents = str_replace('<h2>', '</div><div class="faq-entry"><h2>', $contents) . '</div>';
            $contents = preg_replace_callback('{("faq-entry">)(<h2>)([^<]+)}', function ($matches) use (&$toc) {
                $toc[str_replace(' ', '-', $matches[3])] = $matches[3];

                return $matches[1].'<a id="'.str_replace(' ', '-', $matches[3]).'"></a>'.$matches[2].$matches[3];
            }, $contents);
            $contents = substr($contents, 6);

            $tocHtml = '<h2>Topics</h2><div class="faq-toc">';
            foreach ($toc as $link => $title) {
                $tocHtml .= '<h2 class="toc-link"><a href="#'.$link.'">'.$title.'</a></h2>';
            }
            $tocHtml .= '</div>';
            $contents = $tocHtml . $contents;
        }

        return $this->render('ToranProxyBundle:Home:docs.html.twig', array(
            'content' => $contents,
            'page' => 'docs',
            'doc_page' => $page,
        ));
    }

    public function settingsAction(Request $req)
    {
        $config = $this->get('config');
        $builder = $this->createConfigForm($req, array(
            'packagist_sync' => $config->get('packagist_sync'),
            'public_repositories' => implode("\n", array_map(function ($repo) { return $repo['url']; }, $config->get('public_repositories'))),
            'dist_sync_mode' => $config->get('dist_sync_mode'),
            'git_prefix' => $config->get('git_prefix'),
            'git_path' => $config->get('git_path'),
            'license' => $config->get('license'),
            'license_personal' => $config->get('license_personal'),
        ));

        $builder->add('Save', 'Symfony\Component\Form\Extension\Core\Type\SubmitType');

        $form = $builder->getForm();
        if ($req->getMethod() === 'POST') {
            $form->handleRequest($req);
            $data = $form->getData();

            $satis = array();
            if (!empty($data['satis_conf'])) {
                list($satis, $err) = $this->decodeJson($data['satis_conf']);
                if ($err) {
                    $form->addError(new FormError($err));
                }
            }

            // store previous repo config for comparison below
            $previousRepos = $config->getPublicRepositories();

            if (!count($form->getErrors())) {
                $this->validateLicense($form);
                $this->processConfigForm($form, $config);
            }

            if (!count($form->getErrors())) {
                if (isset($satis['repositories'])) {
                    foreach ($satis['repositories'] as $repoConfig) {
                        foreach ($config->getRepositories() as $repoB) {
                            if ($repoConfig == $repoB->config) {
                                continue 2;
                            }
                        }
                        $config->addRepository(Repository::fromArray($repoConfig, 0));
                    }
                }

                // if repos get removed we need to remove all synced packages from them as well
                $currentRepos = $config->getPublicRepositories();
                $packages = $config->getSyncedPackages();
                foreach ($previousRepos as $repo) {
                    foreach ($currentRepos as $newRepo) {
                        if ($newRepo['origin'] === $repo['origin']) {
                            continue 2;
                        }
                    }

                    $proxy = $this->get('proxy_factory')->createProxy($repo);
                    foreach ($packages as $package => $origin) {
                        if ($origin === $repo['origin']) {
                            $proxy->removePackage($package);
                        }
                    }
                }

                $config->save();

                return $this->redirect($this->generateUrl('home'));
            }
        }

        return $this->render('ToranProxyBundle:Home:settings.html.twig', array('form' => $form->createView(), 'page' => 'settings'));
    }

    protected function decodeJson($json)
    {
        $data = $err = null;
        try {
            $data = JsonFile::parseJson($json, 'satis config');
        } catch (\Exception $e) {
            $err = $e->getMessage();
            $err = preg_replace("{(Parse error [^\r\n]*)\r?\n(.*)}is", '$1<pre>$2</pre>', $err);
        }

        return array($data, $err);
    }

    protected function createConfigForm(Request $req, array $data = array())
    {
        $data = array_merge(array(
            'packagist_sync' => true,
            'public_repositories' => '',
            'dist_sync_mode' => 'lazy',
            'git_prefix' => '',
            'git_path' => '',
            'license' => '',
        ), $data);
        $data['packagist_sync'] = (bool) $data['packagist_sync'];

        $form = $this->createFormBuilder($data)
            ->add('packagist_sync', 'Symfony\Component\Form\Extension\Core\Type\CheckboxType', array(
                'required' => false,
                'label' => 'Proxy packagist.org packages - enables the Packagist proxy repository'
            ))
            ->add('public_repositories', 'Symfony\Component\Form\Extension\Core\Type\TextareaType', array(
                'required' => false,
                'label' => 'Additional Composer repos to proxy (e.g. https://wpackagist.org, https://packages.firegento.com, ...) - one per line'
            ))
            ->add('dist_sync_mode', 'Symfony\Component\Form\Extension\Core\Type\ChoiceType', array(
                'required' => true,
                'choices' => array(
                    'Lazy: every archive is built on demand when you first install a given package\'s version' => 'lazy',
                    'New tags: tags newer than the oldest version you have used will be pre-cached as soon as they are available' => 'new',
                    'All: all releases will be pre-cached as they become available' => 'all',
                ),
                'choices_as_values' => true,
                'label' => 'Which zip archives should be pre-fetched by the cron job?',
                'expanded' => true,
            ))
            ->add('git_path', 'Symfony\Component\Form\Extension\Core\Type\TextType', array(
                'required' => false,
                'label' => 'git path (where to store git clones on this machine, must be writable by the web user)',
                'attr' => array('placeholder' => '/home/git/path/to/mirrors/'),
            ))
            ->add('git_prefix', 'Symfony\Component\Form\Extension\Core\Type\TextType', array(
                'required' => false,
                'label' => 'git clone url (so composer can clone your repositories, e.g. git@your.toran.proxy:path/to/mirrors/)',
                'attr' => array('placeholder' => 'git@' . $req->server->get('HOST') . ':mirrors/'),
            ))
            ->add('license_personal', 'Symfony\Component\Form\Extension\Core\Type\CheckboxType', array(
                'required' => false,
                'label' => 'This instance is for personal use',
            ))
            ->add('license', 'Symfony\Component\Form\Extension\Core\Type\TextareaType', array(
                'required' => false,
                'label' => 'License',
            ))
            ->add('satis_conf', 'Symfony\Component\Form\Extension\Core\Type\TextareaType', array(
                'required' => false,
                'attr' => array('placeholder' => '{ "repositories": [ ... ] }'),
            ))
        ;

        return $form;
    }

    protected function processConfigForm($form, $config)
    {
        $data = $form->getData();
        $config->set('packagist_sync', $data['packagist_sync'] ? 'proxy' : false);
        $config->set('dist_sync_mode', $data['dist_sync_mode']);
        $config->set('git_prefix', $data['git_prefix'] ?: false);
        $config->set('git_path', $data['git_path'] ?: false);
        $config->set('license', $data['license']);
        $config->set('license_personal', $data['license_personal'] ?: false);
        $config->setPublicRepositories($data['public_repositories']);

        if ((!$data['git_path'] && $data['git_prefix']) || ($data['git_path'] && !$data['git_prefix'])) {
            $form->addError(new FormError('Both git path and git prefix must be set or empty'));
        }
    }

    protected function validateLicense($form)
    {
        $data = $form->getData();
        if (empty($data['license']) && empty($data['license_personal'])) {
            $form->addError(new FormError('Missing license, you can <a href="http://toranproxy.com">buy one</a> or check the personal use box below if it applies'));
        }

        if (empty($data['license'])) {
            return;
        }

        $util = $this->get('toran_util');
        try {
            if (!$util->validateLicense($data['license'])) {
                $form->addError(new FormError('Invalid license'));
            }
        } catch (LicenseExpiredException $e) {
            $form->addError(new FormError('Invalid license: ' . $e->getMessage()));
        }
    }
}

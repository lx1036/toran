<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

class AppKernel extends Kernel
{
    public function __construct($environment, $debug)
    {
        date_default_timezone_set('UTC');

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (!empty($info['name']) && $info['name'] === 'root') {
                if (PHP_SAPI === 'cli') {
                    die('Toran\'s console commands should not be executed as root because that would cause file permission issues. Run commands as the web user (www-data or equivalent)');
                }
                die('Toran should not be executed as root because that would cause file permission issues.');
            }
        }

        parent::__construct($environment, $debug);
    }

    public function registerBundles()
    {
        $bundles = array(
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new Symfony\Bundle\TwigBundle\TwigBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),
            new Toran\ProxyBundle\ToranProxyBundle(),
        );

        if (in_array($this->getEnvironment(), array('dev', 'test'))) {
            $bundles[] = new Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
            $bundles[] = new Sensio\Bundle\DistributionBundle\SensioDistributionBundle();

            if ('dev' === $this->getEnvironment()) {
                $bundles[] = new Symfony\Bundle\WebServerBundle\WebServerBundle();
            }
        }

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(__DIR__.'/config/config_'.$this->getEnvironment().'.yml');
    }

    public function boot()
    {
        $res = parent::boot();

        putenv('COMPOSER_HOME='.$this->getContainer()->getParameter('composer_home'));
        putenv('COMPOSER_CACHE_DIR='.$this->getContainer()->getParameter('composer_cache_dir'));
        if (!$this->getContainer()->getParameter('toran_host')) {
            die('Toran requires that the toran_host parameter is configured in app/config/parameters.yml
Do not forget to delete app/cache/prod/* after changing the config for the changes to take effect');
        }

        return $res;
    }
}

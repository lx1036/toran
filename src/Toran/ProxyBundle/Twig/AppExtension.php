<?php

namespace Toran\ProxyBundle\Twig;

class AppExtension extends \Twig_Extension
{
    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('link_to_origin', array($this, 'linkToOrigin')),
        );
    }

    public function linkToOrigin($package, $origin)
    {
        if ($origin === 'packages.firegento.com') {
            return "https://{$origin}/#{$package}";
        }
        if ($origin === 'wpackagist.org') {
            preg_match('{([^/]+)$}', $package, $match);
            $packageName = $match[1];

            return "https://{$origin}/search?q={$packageName}&type=any&search=";
        }

        return "https://{$origin}/packages/{$package}";
    }

    public function getName()
    {
        return 'app_extension';
    }
}

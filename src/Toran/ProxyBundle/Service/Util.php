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

use Composer\Json\JsonFile;
use Composer\Package\Version\VersionParser;
use Toran\ProxyBundle\Exception\LicenseExpiredException;

class Util
{
    private $rootDir;
    private $cacheDir;
    private $config;

    public function __construct($rootDir, $cacheDir, Configuration $config)
    {
        $this->rootDir  = $rootDir;
        $this->cacheDir = $cacheDir;
        $this->config   = $config;
    }

    public function validateLicense($license, $checkDate = true)
    {
        $pubkeyid = openssl_pkey_get_public('file://' . $this->rootDir . '/Resources/pubkey.cer');

        $license = preg_replace('{^.*?-----BEGIN TORAN LICENSE-----}s', '', $license);
        $license = preg_replace('{(-----END TORAN LICENSE-----.*)}s', '', $license);
        $license = preg_replace('{\s+}', '', $license);
        $license = base64_decode($license, true);
        if (!strpos($license, '|')) {
            return false;
        }
        [$signature, $license] = explode('|', $license, 2);
        $signature             = base64_decode($signature, true);

        if (defined('OPENSSL_ALGO_SHA256')) {
            $algo =  OPENSSL_ALGO_SHA256;
        } else {
            $algo = 'SHA256';
        }

        if (!in_array('SHA256', \openssl_get_md_methods(), true) || 1 === \openssl_verify($license, $signature, $pubkeyid, $algo)) {
            $details = JsonFile::parseJson($license, 'license');

            if ($checkDate) {
                if ($this->isLicenseExpired($details)) {
                    $expiresAt = $this->getExpiresAt();
                    $email     = $this->getEmail();

                    throw new LicenseExpiredException('License expired on ' . $expiresAt->format('Y-m-d') . ', head to <a href="https://toranproxy.com/renew?email=' . $email . '">toranproxy.com</a> to renew it');
                }

                file_put_contents($this->cacheDir . '/license.meta', '<?php return ' . var_export($details, true) . ';');
            }

            return $details;
        }

        return false;
    }

    public function getProductName()
    {
        if ($license = $this->config->get('license')) {
            if (file_exists($this->cacheDir . '/license.meta')) {
                $details = include $this->cacheDir . '/license.meta';
                if ($this->isLicenseExpired($details)) {
                    return 'expired';
                }
            } else {
                try {
                    $details = $this->validateLicense($license);
                } catch (LicenseExpiredException $e) {
                    return 'expired';
                }
            }
            if ($details && false !== ($index = array_search('product', $details, true)) && isset($details[$index + 1])) {
                return $details[$index + 1];
            }
        } elseif ($this->config->get('license_personal')) {
            return 'personal';
        }

        return 'unknown';
    }

    public function getExpiresAt()
    {
        if ($license = $this->config->get('license')) {
            if (file_exists($this->cacheDir . '/license.meta')) {
                $details = include $this->cacheDir . '/license.meta';
            } else {
                $details = $this->validateLicense($license, false);
            }
            if ($details && false !== ($index = array_search('expires', $details, true)) && isset($details[$index + 1])) {
                return new \DateTime($details[$index + 1]);
            }
        }

        return new \DateTime('+1 year');
    }

    public function getEmail()
    {
        if ($license = $this->config->get('license')) {
            if (file_exists($this->cacheDir . '/license.meta')) {
                $details = include $this->cacheDir . '/license.meta';
            } else {
                $details = $this->validateLicense($license, false);
            }
            if ($details && false !== ($index = array_search('email', $details, true)) && isset($details[$index + 1])) {
                return $details[$index + 1];
            }
        }

        return '';
    }

    public static function sortByVersion(array $versions)
    {
        if (!count($versions)) {
            return $versions;
        }

        if (is_object(reset($versions))) {
            usort($versions, function ($a, $b) {
                return Util::compareVersions($a->getVersion(), $b->getVersion());
            });
        } else {
            $parser = new VersionParser();
            usort($versions, function ($a, $b) use ($parser) {
                $a = isset($a['version_normalized']) ? $a['version_normalized'] : $parser->normalize($a['version']);
                $b = isset($b['version_normalized']) ? $b['version_normalized'] : $parser->normalize($b['version']);

                return Util::compareVersions($a, $b);
            });
        }

        return $versions;
    }

    public static function compareVersions($a, $b)
    {
        $aDev = false !== strpos($a, 'dev');
        $bDev = false !== strpos($b, 'dev');
        if (!$aDev && !$bDev) {
            return version_compare($a, $b);
        }
        if ($aDev && !$bDev) {
            return -1;
        }
        if (!$aDev && $bDev) {
            return 1;
        }

        return 0;
    }

    private function isLicenseExpired(array $details)
    {
        $index     = array_search('expires', $details, true);
        $expiresAt = new \DateTime($details[$index + 1]);
        $now       = max(new \DateTime(), new \DateTime('2016-04-01'));

        return $expiresAt < $now;
    }
}

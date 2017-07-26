<?php

/*
 * This file is part of the Toran package.
 *
 * (c) Nelmio <hello@nelm.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Toran\ProxyBundle\Model;

use Symfony\Validator\Constraints as Assert;

class Repository
{
    public $id;

    /**
     * @Assert\Choice(choices={"vcs", "git", "hg", "svn", "artifact", "pear"})
     */
    public $type;

    /**
     * @Assert\Url
     */
    public $url;

    public $config;

    public static function fromArray(array $data, $id)
    {
        $repo = new self;

        $repo->id = $id;
        $repo->type = $data['type'];
        if (isset($data['url'])) {
            $repo->url = $data['url'];
        }
        $repo->config = $data;

        return $repo;
    }

    public function getDigest()
    {
        return sha1(json_encode($this->getCleanConfig()));
    }

    public function getCleanConfig()
    {
        $cleanConf = array();

        foreach ($this->config as $index => $val) {
            if (substr($index, 0, 6) !== 'toran_') {
                $cleanConf[$index] = $val;
            }
        }

        return $cleanConf;
    }

    public function getNames()
    {
        return isset($this->config['toran_package_names']) ? $this->config['toran_package_names'] : null;
    }
}

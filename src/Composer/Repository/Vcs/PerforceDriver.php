<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository\Vcs;

use Composer\Config;
use Composer\Cache;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\ProcessExecutor;
use Composer\Util\Perforce;

/**
 * @author Matt Whittom <Matt.Whittom@veteransunited.com>
 */
class PerforceDriver extends VcsDriver
{
    /** @var string */
    protected $depot;
    /** @var string */
    protected $branch;
    /** @var ?Perforce */
    protected $perforce = null;

    /**
     * @inheritDoc
     */
    public function initialize()
    {
        $this->depot = $this->repoConfig['depot'];
        $this->branch = '';
        if (!empty($this->repoConfig['branch'])) {
            $this->branch = $this->repoConfig['branch'];
        }

        $this->initPerforce($this->repoConfig);
        $this->perforce->p4Login();
        $this->perforce->checkStream();

        $this->perforce->writeP4ClientSpec();
        $this->perforce->connectClient();
    }

    /**
     * @param array<string, mixed> $repoConfig
     *
     * @return void
     */
    private function initPerforce($repoConfig): void
    {
        if (!empty($this->perforce)) {
            return;
        }

        if (!Cache::isUsable((string) $this->config->get('cache-vcs-dir'))) {
            throw new \RuntimeException('PerforceDriver requires a usable cache directory, and it looks like you set it to be disabled');
        }

        $repoDir = $this->config->get('cache-vcs-dir') . '/' . $this->depot;
        $this->perforce = Perforce::create($repoConfig, $this->getUrl(), $repoDir, $this->process, $this->io);
    }

    /**
     * @inheritDoc
     */
    public function getFileContent($file, $identifier)
    {
        return $this->perforce->getFileContent($file, $identifier);
    }

    /**
     * @inheritDoc
     */
    public function getChangeDate($identifier)
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getRootIdentifier()
    {
        return $this->branch;
    }

    /**
     * @inheritDoc
     */
    public function getBranches()
    {
        return $this->perforce->getBranches();
    }

    /**
     * @inheritDoc
     */
    public function getTags()
    {
        return $this->perforce->getTags();
    }

    /**
     * @inheritDoc
     */
    public function getDist($identifier)
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSource($identifier)
    {
        return array(
            'type' => 'perforce',
            'url' => $this->repoConfig['url'],
            'reference' => $identifier,
            'p4user' => $this->perforce->getUser(),
        );
    }

    /**
     * @inheritDoc
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @inheritDoc
     */
    public function hasComposerFile($identifier)
    {
        $composerInfo = $this->perforce->getComposerInformation('//' . $this->depot . '/' . $identifier);

        return !empty($composerInfo);
    }

    /**
     * @inheritDoc
     */
    public function getContents($url)
    {
        throw new \BadMethodCallException('Not implemented/used in PerforceDriver');
    }

    /**
     * @inheritDoc
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false)
    {
        if ($deep || Preg::isMatch('#\b(perforce|p4)\b#i', $url)) {
            return Perforce::checkServerExists($url, new ProcessExecutor($io));
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function cleanup()
    {
        $this->perforce->cleanupClientSpec();
        $this->perforce = null;
    }

    /**
     * @return string
     */
    public function getDepot()
    {
        return $this->depot;
    }

    /**
     * @return string
     */
    public function getBranch()
    {
        return $this->branch;
    }
}

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

namespace Composer\Package;

use Composer\Semver\Constraint\Constraint;
use Composer\Package\Version\VersionParser;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AliasPackage extends BasePackage
{
    /** @var string */
    protected $version;
    /** @var string */
    protected $prettyVersion;
    /** @var bool */
    protected $dev;
    /** @var bool */
    protected $rootPackageAlias = false;
    /**
     * @var string
     * @phpstan-var 'stable'|'RC'|'beta'|'alpha'|'dev'
     */
    protected $stability;
    /** @var bool */
    protected $hasSelfVersionRequires = false;

    /** @var BasePackage */
    protected $aliasOf;
    /** @var Link[] */
    protected $requires;
    /** @var Link[] */
    protected $devRequires;
    /** @var Link[] */
    protected $conflicts;
    /** @var Link[] */
    protected $provides;
    /** @var Link[] */
    protected $replaces;

    /**
     * All descendants' constructors should call this parent constructor
     *
     * @param BasePackage $aliasOf       The package this package is an alias of
     * @param string      $version       The version the alias must report
     * @param string      $prettyVersion The alias's non-normalized version
     */
    public function __construct(BasePackage $aliasOf, $version, $prettyVersion)
    {
        parent::__construct($aliasOf->getName());

        $this->version = $version;
        $this->prettyVersion = $prettyVersion;
        $this->aliasOf = $aliasOf;
        $this->stability = VersionParser::parseStability($version);
        $this->dev = $this->stability === 'dev';

        foreach (Link::$TYPES as $type) {
            $links = $aliasOf->{'get' . ucfirst($type)}();
            $this->$type = $this->replaceSelfVersionDependencies($links, $type);
        }
    }

    /**
     * @return BasePackage
     */
    public function getAliasOf()
    {
        return $this->aliasOf;
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @inheritDoc
     */
    public function getStability(): string
    {
        return $this->stability;
    }

    /**
     * @inheritDoc
     */
    public function getPrettyVersion(): string
    {
        return $this->prettyVersion;
    }

    /**
     * @inheritDoc
     */
    public function isDev(): bool
    {
        return $this->dev;
    }

    /**
     * @inheritDoc
     */
    public function getRequires(): array
    {
        return $this->requires;
    }

    /**
     * @inheritDoc
     * @return array<string|int, Link>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @inheritDoc
     * @return array<string|int, Link>
     */
    public function getProvides(): array
    {
        return $this->provides;
    }

    /**
     * @inheritDoc
     * @return array<string|int, Link>
     */
    public function getReplaces(): array
    {
        return $this->replaces;
    }

    /**
     * @inheritDoc
     */
    public function getDevRequires(): array
    {
        return $this->devRequires;
    }

    /**
     * Stores whether this is an alias created by an aliasing in the requirements of the root package or not
     *
     * Use by the policy for sorting manually aliased packages first, see #576
     *
     * @param bool $value
     *
     * @return mixed
     */
    public function setRootPackageAlias($value)
    {
        return $this->rootPackageAlias = $value;
    }

    /**
     * @see setRootPackageAlias
     * @return bool
     */
    public function isRootPackageAlias(): bool
    {
        return $this->rootPackageAlias;
    }

    /**
     * @param Link[]       $links
     * @param Link::TYPE_* $linkType
     *
     * @return Link[]
     */
    protected function replaceSelfVersionDependencies(array $links, $linkType): array
    {
        // for self.version requirements, we use the original package's branch name instead, to avoid leaking the magic dev-master-alias to users
        $prettyVersion = $this->prettyVersion;
        if ($prettyVersion === VersionParser::DEFAULT_BRANCH_ALIAS) {
            $prettyVersion = $this->aliasOf->getPrettyVersion();
        }

        if (\in_array($linkType, array(Link::TYPE_CONFLICT, Link::TYPE_PROVIDE, Link::TYPE_REPLACE), true)) {
            $newLinks = array();
            foreach ($links as $link) {
                // link is self.version, but must be replacing also the replaced version
                if ('self.version' === $link->getPrettyConstraint()) {
                    $newLinks[] = new Link($link->getSource(), $link->getTarget(), $constraint = new Constraint('=', $this->version), $linkType, $prettyVersion);
                    $constraint->setPrettyString($prettyVersion);
                }
            }
            $links = array_merge($links, $newLinks);
        } else {
            foreach ($links as $index => $link) {
                if ('self.version' === $link->getPrettyConstraint()) {
                    if ($linkType === Link::TYPE_REQUIRE) {
                        $this->hasSelfVersionRequires = true;
                    }
                    $links[$index] = new Link($link->getSource(), $link->getTarget(), $constraint = new Constraint('=', $this->version), $linkType, $prettyVersion);
                    $constraint->setPrettyString($prettyVersion);
                }
            }
        }

        return $links;
    }

    /**
     * @return bool
     */
    public function hasSelfVersionRequires(): bool
    {
        return $this->hasSelfVersionRequires;
    }

    public function __toString()
    {
        return parent::__toString().' ('.($this->rootPackageAlias ? 'root ' : ''). 'alias of '.$this->aliasOf->getVersion().')';
    }

    /***************************************
     * Wrappers around the aliased package *
     ***************************************/

    public function getType(): string
    {
        return $this->aliasOf->getType();
    }

    public function getTargetDir(): ?string
    {
        return $this->aliasOf->getTargetDir();
    }

    public function getExtra(): array
    {
        return $this->aliasOf->getExtra();
    }

    public function setInstallationSource($type): void
    {
        $this->aliasOf->setInstallationSource($type);
    }

    public function getInstallationSource(): ?string
    {
        return $this->aliasOf->getInstallationSource();
    }

    public function getSourceType(): ?string
    {
        return $this->aliasOf->getSourceType();
    }

    public function getSourceUrl(): ?string
    {
        return $this->aliasOf->getSourceUrl();
    }

    public function getSourceUrls(): array
    {
        return $this->aliasOf->getSourceUrls();
    }

    public function getSourceReference(): ?string
    {
        return $this->aliasOf->getSourceReference();
    }

    public function setSourceReference($reference): void
    {
        $this->aliasOf->setSourceReference($reference);
    }

    public function setSourceMirrors($mirrors): void
    {
        $this->aliasOf->setSourceMirrors($mirrors);
    }

    public function getSourceMirrors(): ?array
    {
        return $this->aliasOf->getSourceMirrors();
    }

    public function getDistType(): ?string
    {
        return $this->aliasOf->getDistType();
    }

    public function getDistUrl(): ?string
    {
        return $this->aliasOf->getDistUrl();
    }

    public function getDistUrls(): array
    {
        return $this->aliasOf->getDistUrls();
    }

    public function getDistReference(): ?string
    {
        return $this->aliasOf->getDistReference();
    }

    public function setDistReference($reference): void
    {
        $this->aliasOf->setDistReference($reference);
    }

    public function getDistSha1Checksum(): ?string
    {
        return $this->aliasOf->getDistSha1Checksum();
    }

    public function setTransportOptions(array $options): void
    {
        $this->aliasOf->setTransportOptions($options);
    }

    public function getTransportOptions(): array
    {
        return $this->aliasOf->getTransportOptions();
    }

    public function setDistMirrors($mirrors): void
    {
        $this->aliasOf->setDistMirrors($mirrors);
    }

    public function getDistMirrors(): ?array
    {
        return $this->aliasOf->getDistMirrors();
    }

    public function getAutoload(): array
    {
        return $this->aliasOf->getAutoload();
    }

    public function getDevAutoload(): array
    {
        return $this->aliasOf->getDevAutoload();
    }

    public function getIncludePaths(): array
    {
        return $this->aliasOf->getIncludePaths();
    }

    public function getReleaseDate(): ?\DateTime
    {
        return $this->aliasOf->getReleaseDate();
    }

    public function getBinaries(): array
    {
        return $this->aliasOf->getBinaries();
    }

    public function getSuggests(): array
    {
        return $this->aliasOf->getSuggests();
    }

    public function getNotificationUrl(): ?string
    {
        return $this->aliasOf->getNotificationUrl();
    }

    public function isDefaultBranch(): bool
    {
        return $this->aliasOf->isDefaultBranch();
    }

    public function setDistUrl($url): void
    {
        $this->aliasOf->setDistUrl($url);
    }

    public function setDistType($type): void
    {
        $this->aliasOf->setDistType($type);
    }

    public function setSourceDistReferences($reference): void
    {
        $this->aliasOf->setSourceDistReferences($reference);
    }
}

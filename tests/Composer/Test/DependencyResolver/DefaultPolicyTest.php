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

namespace Composer\Test\DependencyResolver;

use Composer\Repository\ArrayRepository;
use Composer\Repository\LockArrayRepository;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\Package\Link;
use Composer\Package\AliasPackage;
use Composer\Repository\RepositorySet;
use Composer\Semver\Constraint\Constraint;
use Composer\Test\TestCase;

class DefaultPolicyTest extends TestCase
{
    /** @var RepositorySet */
    protected $repositorySet;
    /** @var ArrayRepository */
    protected $repo;
    /** @var LockArrayRepository */
    protected $repoLocked;
    /** @var DefaultPolicy */
    protected $policy;

    public function setUp(): void
    {
        $this->repositorySet = new RepositorySet('dev');
        $this->repo = new ArrayRepository;
        $this->repoLocked = new LockArrayRepository;

        $this->policy = new DefaultPolicy;
    }

    public function testSelectSingle(): void
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $literals = array($packageA->getId());
        $expected = array($packageA->getId());

        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testSelectNewest(): void
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.0'));
        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA2->getId());

        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testSelectNewestPicksLatest(): void
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '1.0.1-alpha'));
        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA2->getId());

        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testSelectNewestPicksLatestStableWithPreferStable(): void
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '1.0.1-alpha'));
        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA1->getId());

        $policy = new DefaultPolicy(true);
        $selected = $policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testSelectNewestWithDevPicksNonDev(): void
    {
        $this->repo->addPackage($packageA1 = $this->getPackage('A', 'dev-foo'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '1.0.0'));
        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA2->getId());

        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testRepositoryOrderingAffectsPriority(): void
    {
        $repo1 = new ArrayRepository;
        $repo2 = new ArrayRepository;

        $repo1->addPackage($package1 = $this->getPackage('A', '1.0'));
        $repo1->addPackage($package2 = $this->getPackage('A', '1.1'));
        $repo2->addPackage($package3 = $this->getPackage('A', '1.1'));
        $repo2->addPackage($package4 = $this->getPackage('A', '1.2'));

        $this->repositorySet->addRepository($repo1);
        $this->repositorySet->addRepository($repo2);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $literals = array($package1->getId(), $package2->getId(), $package3->getId(), $package4->getId());
        $expected = array($package2->getId());
        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);

        $this->repositorySet = new RepositorySet('dev');
        $this->repositorySet->addRepository($repo2);
        $this->repositorySet->addRepository($repo1);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $expected = array($package4->getId());
        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testSelectLocalReposFirst(): void
    {
        $repoImportant = new ArrayRepository;

        $this->repo->addPackage($packageA = $this->getPackage('A', 'dev-master'));
        $this->repo->addPackage($packageAAlias = new AliasPackage($packageA, '2.1.9999999.9999999-dev', '2.1.x-dev'));
        $repoImportant->addPackage($packageAImportant = $this->getPackage('A', 'dev-feature-a'));
        $repoImportant->addPackage($packageAAliasImportant = new AliasPackage($packageAImportant, '2.1.9999999.9999999-dev', '2.1.x-dev'));
        $repoImportant->addPackage($packageA2Important = $this->getPackage('A', 'dev-master'));
        $repoImportant->addPackage($packageA2AliasImportant = new AliasPackage($packageA2Important, '2.1.9999999.9999999-dev', '2.1.x-dev'));
        $packageAAliasImportant->setRootPackageAlias(true);

        $this->repositorySet->addRepository($repoImportant);
        $this->repositorySet->addRepository($this->repo);
        $this->repositorySet->addRepository($this->repoLocked);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $packages = $pool->whatProvides('a', new Constraint('=', '2.1.9999999.9999999-dev'));
        $literals = array();
        foreach ($packages as $package) {
            $literals[] = $package->getId();
        }

        $expected = array($packageAAliasImportant->getId());

        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testSelectAllProviders(): void
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));

        $packageA->setProvides(array('x' => new Link('A', 'X', new Constraint('==', '1.0'), Link::TYPE_PROVIDE)));
        $packageB->setProvides(array('x' => new Link('B', 'X', new Constraint('==', '1.0'), Link::TYPE_PROVIDE)));

        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackages(array('A', 'B'), $this->repoLocked);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testPreferNonReplacingFromSameRepo(): void
    {
        $this->repo->addPackage($packageA = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageB = $this->getPackage('B', '2.0'));

        $packageB->setReplaces(array('a' => new Link('B', 'A', new Constraint('==', '1.0'), Link::TYPE_REPLACE)));

        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackages(array('A', 'B'), $this->repoLocked);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }

    public function testPreferReplacingPackageFromSameVendor(): void
    {
        // test with default order
        $this->repo->addPackage($packageB = $this->getPackage('vendor-b/replacer', '1.0'));
        $this->repo->addPackage($packageA = $this->getPackage('vendor-a/replacer', '1.0'));

        $packageA->setReplaces(array('vendor-a/package' => new Link('vendor-a/replacer', 'vendor-a/package', new Constraint('==', '1.0'), Link::TYPE_REPLACE)));
        $packageB->setReplaces(array('vendor-a/package' => new Link('vendor-b/replacer', 'vendor-a/package', new Constraint('==', '1.0'), Link::TYPE_REPLACE)));

        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackages(array('vendor-a/replacer', 'vendor-b/replacer'), $this->repoLocked);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($pool, $literals, 'vendor-a/package');
        $this->assertEquals($expected, $selected);

        // test with reversed order in repo
        $repo = new ArrayRepository;
        $repo->addPackage($packageA = clone $packageA);
        $repo->addPackage($packageB = clone $packageB);

        $repositorySet = new RepositorySet('dev');
        $repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackages(array('vendor-a/replacer', 'vendor-b/replacer'), $this->repoLocked);

        $literals = array($packageA->getId(), $packageB->getId());
        $expected = $literals;

        $selected = $this->policy->selectPreferredPackages($pool, $literals, 'vendor-a/package');
        $this->assertSame($expected, $selected);
    }

    public function testSelectLowest(): void
    {
        $policy = new DefaultPolicy(false, true);

        $this->repo->addPackage($packageA1 = $this->getPackage('A', '1.0'));
        $this->repo->addPackage($packageA2 = $this->getPackage('A', '2.0'));
        $this->repositorySet->addRepository($this->repo);

        $pool = $this->repositorySet->createPoolForPackage('A', $this->repoLocked);

        $literals = array($packageA1->getId(), $packageA2->getId());
        $expected = array($packageA1->getId());

        $selected = $policy->selectPreferredPackages($pool, $literals);

        $this->assertSame($expected, $selected);
    }
}

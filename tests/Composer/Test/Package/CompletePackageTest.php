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

namespace Composer\Test\Package;

use Composer\Package\Package;
use Composer\Semver\VersionParser;
use Composer\Test\TestCase;

class CompletePackageTest extends TestCase
{
    /**
     * Memory package naming, versioning, and marshalling semantics provider
     *
     * demonstrates several versioning schemes
     */
    public function providerVersioningSchemes(): array
    {
        $provider[] = array('foo',              '1-beta');
        $provider[] = array('node',             '0.5.6');
        $provider[] = array('li3',              '0.10');
        $provider[] = array('mongodb_odm',      '1.0.0BETA3');
        $provider[] = array('DoctrineCommon',   '2.2.0-DEV');

        return $provider;
    }

    /**
     * @dataProvider providerVersioningSchemes
     *
     * @param string $name
     * @param string $version
     */
    public function testPackageHasExpectedNamingSemantics($name, $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        $this->assertEquals(strtolower($name), $package->getName());
    }

    /**
     * @dataProvider providerVersioningSchemes
     *
     * @param string $name
     * @param string $version
     */
    public function testPackageHasExpectedVersioningSemantics($name, $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        $this->assertEquals($version, $package->getPrettyVersion());
        $this->assertEquals($normVersion, $package->getVersion());
    }

    /**
     * @dataProvider providerVersioningSchemes
     *
     * @param string $name
     * @param string $version
     */
    public function testPackageHasExpectedMarshallingSemantics($name, $version): void
    {
        $versionParser = new VersionParser();
        $normVersion = $versionParser->normalize($version);
        $package = new Package($name, $normVersion, $version);
        $this->assertEquals(strtolower($name).'-'.$normVersion, (string) $package);
    }

    public function testGetTargetDir(): void
    {
        $package = new Package('a', '1.0.0.0', '1.0');

        $this->assertNull($package->getTargetDir());

        $package->setTargetDir('./../foo/');
        $this->assertEquals('foo/', $package->getTargetDir());

        $package->setTargetDir('foo/../../../bar/');
        $this->assertEquals('foo/bar/', $package->getTargetDir());

        $package->setTargetDir('../..');
        $this->assertEquals('', $package->getTargetDir());

        $package->setTargetDir('..');
        $this->assertEquals('', $package->getTargetDir());

        $package->setTargetDir('/..');
        $this->assertEquals('', $package->getTargetDir());

        $package->setTargetDir('/foo/..');
        $this->assertEquals('foo/', $package->getTargetDir());

        $package->setTargetDir('/foo/..//bar');
        $this->assertEquals('foo/bar', $package->getTargetDir());
    }
}

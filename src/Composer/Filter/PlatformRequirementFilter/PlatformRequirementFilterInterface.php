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

namespace Composer\Filter\PlatformRequirementFilter;

interface PlatformRequirementFilterInterface
{
    /**
     * @param string $req
     * @return bool
     */
    public function isIgnored($req): bool;
}

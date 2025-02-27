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

namespace Composer\Test\Command;

use Composer\Composer;
use Composer\Config;
use Composer\Script\Event as ScriptEvent;
use Composer\Test\TestCase;

class RunScriptCommandTest extends TestCase
{
    /**
     * @dataProvider getDevOptions
     * @param bool $dev
     * @param bool $noDev
     */
    public function testDetectAndPassDevModeToEventAndToDispatching($dev, $noDev): void
    {
        $scriptName = 'testScript';

        $input = $this->getMockBuilder('Symfony\Component\Console\Input\InputInterface')->getMock();
        $input
            ->method('getOption')
            ->will($this->returnValueMap(array(
                array('list', false),
                array('dev', $dev),
                array('no-dev', $noDev),
            )));

        $input
            ->method('getArgument')
            ->will($this->returnValueMap(array(
                array('script', $scriptName),
                array('args', array()),
            )));
        $input
            ->method('hasArgument')
            ->with('command')
            ->willReturn(false);
        $input
            ->method('isInteractive')
            ->willReturn(false);

        $output = $this->getMockBuilder('Symfony\Component\Console\Output\OutputInterface')->getMock();

        $expectedDevMode = $dev || !$noDev;

        $ed = $this->getMockBuilder('Composer\EventDispatcher\EventDispatcher')
            ->disableOriginalConstructor()
            ->getMock();

        $ed->expects($this->once())
            ->method('hasEventListeners')
            ->with($this->callback(function (ScriptEvent $event) use ($scriptName, $expectedDevMode): bool {
                return $event->getName() === $scriptName
                && $event->isDevMode() === $expectedDevMode;
            }))
            ->willReturn(true);

        $ed->expects($this->once())
            ->method('dispatchScript')
            ->with($scriptName, $expectedDevMode, array())
            ->willReturn(0);

        $composer = $this->createComposerInstance();
        $composer->setEventDispatcher($ed);

        $command = $this->getMockBuilder('Composer\Command\RunScriptCommand')
            ->onlyMethods(array(
                'mergeApplicationDefinition',
                'getSynopsis',
                'initialize',
                'requireComposer',
            ))
            ->getMock();
        $command->expects($this->any())->method('requireComposer')->willReturn($composer);

        $command->run($input, $output);
    }

    /** @return bool[][] **/
    public function getDevOptions(): array
    {
        return array(
            array(true, true),
            array(true, false),
            array(false, true),
            array(false, false),
        );
    }

    /** @return Composer **/
    private function createComposerInstance(): Composer
    {
        $composer = new Composer;
        $config = new Config;
        $composer->setConfig($config);

        return $composer;
    }
}

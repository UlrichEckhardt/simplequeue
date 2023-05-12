<?php

declare(strict_types=1);

namespace integration;

use PHPUnit\Framework\TestCase;
use simpleQueue\Configuration\Configuration;
use simpleQueue\Factory;
use simpleQueue\Infrastructure\Directory;
use simpleQueue\Infrastructure\Logger\SimpleConsoleLogger;
use simpleQueue\Infrastructure\Uuid;
use simpleQueue\Job\Job;
use simpleQueue\Job\JobPayload;
use simpleQueue\Job\JobType;

/**
 * @covers \simpleQueue\Factory
 * @covers \simpleQueue\Infrastructure\ForkingProcessingStrategy
 * @covers \simpleQueue\Infrastructure\Executor
 * @covers \simpleQueue\Event\LogEmitter
 * @covers \simpleQueue\Job\JobCollection
 * @covers \simpleQueue\Infrastructure\JobMover
 * @covers \simpleQueue\Infrastructure\JobReader
 * @covers \simpleQueue\Infrastructure\Directory
 * @covers \simpleQueue\Infrastructure\Filename
 * @covers \simpleQueue\Infrastructure\JobWriter
 * @covers \simpleQueue\Job\Job
 * @covers \simpleQueue\Job\JobPayload
 * @covers \simpleQueue\Job\JobType
 * @covers \simpleQueue\Infrastructure\Uuid
 */
class SimpleQueueTest extends TestCase
{
    private Factory $factory;

    private string $firstJobId = '123abc';

    private string $secondJobId = '456def';

    public function setUp(): void
    {
        parent::setUp();

        $configurationMock = $this->createMock(Configuration::class);
        $configurationMock->method('getInboxDirectory')->willReturn(Directory::fromString(__DIR__.'/../queue/inbox'));
        $configurationMock->method('getFinishedDirectory')->willReturn(Directory::fromString(__DIR__.'/../queue/finished'));
        $configurationMock->method('getFailedDirectory')->willReturn(Directory::fromString(__DIR__.'/../queue/failed'));
        $configurationMock->method('getProgressDirectory')->willReturn(Directory::fromString(__DIR__.'/../queue/progress'));
        $configurationMock->method('getMaxForkChilds')->willReturn(1);

        $this->factory = new Factory($configurationMock);
        $jobWriter = $this->factory->createJobWriter();

        $jobWriter->store(
            new Job(
                Uuid::fromString($this->firstJobId),
                JobType::fromString('sample'),
                JobPayload::fromString('Hello you simple world!')
            )
        );

        $jobWriter->store(
            new Job(
                Uuid::fromString($this->secondJobId),
                JobType::fromString('sample'),
                JobPayload::fromString('2nd Job')
            )
        );
    }

    public function testBiggerScope(): void
    {
        $consoleLoggerMock = $this->createMock(SimpleConsoleLogger::class);

        $processingStrategy = $this->factory->createForkingProcessingStrategy();
        $processingStrategy->getLogEmitter()->addSubscriber($consoleLoggerMock);

        $processingStrategy->process(($this->factory->createJobReader())->retrieveAllJobs());

        $this->assertTrue(file_exists(__DIR__.'/../queue/finished/'.$this->firstJobId));
    }
}

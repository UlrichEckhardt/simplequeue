<?php
/**
 * event processor based on Linux inotify
 *
 * See
 * - https://www.php.net/manual/en/book.inotify.php
 */
declare(strict_types=1);

namespace simpleQueue\Example;

use Exception;
use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use simpleQueue\Configuration\Configuration;
use simpleQueue\Event\Event;
use simpleQueue\Factory;
use simpleQueue\Infrastructure\JobInfrastructureException;
use simpleQueue\Infrastructure\Logger\Subscriber;
use simpleQueue\Job\Job;
use simpleQueue\Job\JobId;
use simpleQueue\Job\JobPayload;
use simpleQueue\Job\JobType;

require_once __DIR__.'/../../vendor/autoload.php';

if (! extension_loaded('inotify')) {
    throw new Exception('inotify extension is not loaded');
}

$configuration = new Configuration();

$factory = new Factory($configuration);
$jobReader = $factory->createJobReader();
$processingStrategy = $factory->createForkingProcessingStrategy();
$processingStrategy->getLogEmitter()->addSubscriber(
    new class implements Subscriber
    {
        public function notify(Event $event): void
        {
            echo posix_getpid().': log event: '.get_class($event).' '.json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        }
    }
);

/**
 * @template-implements  IteratorAggregate<Job>
 */
class Emitter implements IteratorAggregate
{
    private Configuration $configuration;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return  Generator<Job>
     */
    public function getIterator(): Generator
    {
        // configure a watch on the inbox directory
        $inotify = inotify_init();
        if ($inotify === false) {
            throw new Exception('inotify_init() failed');
        }
        $watch = inotify_add_watch(
            $inotify,
            $this->configuration->getInboxDirectory()->toString(),
            IN_CLOSE_WRITE | IN_MOVED_TO
        );
        if ($watch === false) {
            fclose($inotify);
            throw new Exception('inotify_add_watch() failed');
        }

        try {
            echo posix_getpid().': processing inotify events'.PHP_EOL;
            while (true) {
                // TODO: process existing jobs
                // $processingStrategy->process($jobReader->retrieveAllJobs());

                // wait for an inotify event
                // If between adding the watch above and processing existing jobs
                // another one was added, it will be processed already, resulting
                // in a no-op loop before ending up here again.
                // TODO: Restructure this so that only the inotify events trigger
                // job execution. However, this _must_ handle existing events and
                // it better avoid race condition when switching between the two.
                $events = inotify_read($inotify);
                if ($events === false) {
                    throw new Exception('inotify_read() failed');
                }
                foreach ($events as $event) {
                    echo posix_getpid().': inotify event: '.json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
                    switch ($event['mask']) {
                        case IN_CLOSE_WRITE:
                        case IN_MOVED_TO:
                            yield $this->read($event['name']);
                            break;
                        case IN_IGNORED:
                            // this seems to occur after processing a job
                            echo posix_getpid().': ignoring event'.PHP_EOL;
                            break;
                        default:
                            echo posix_getpid().': unexpected event'.PHP_EOL;

                            return;
                    }
                }
            }
        } finally {
            echo posix_getpid().': closing inotify stream'.PHP_EOL;
            fclose($inotify);
        }
    }

    private function read(string $filename): Job
    {
        $content = @file_get_contents($this->configuration->getInboxDirectory()->toString().'/'.$filename);
        if (! $content) {
            throw new InvalidArgumentException('Job File could not be read.');
        }

        $decodedContent = json_decode($content);
        if (is_null($decodedContent)) {
            throw new JobInfrastructureException(json_last_error_msg());
        }

        if (! isset($decodedContent->jobId)) {
            throw new JobInfrastructureException('Missing job id in job file.');
        }

        if (! isset($decodedContent->jobPayload)) {
            throw new JobInfrastructureException('Missing payload id in job file.');
        }

        return new Job(
            JobId::fromString($decodedContent->jobId),
            JobType::fromString('sample'),
            JobPayload::fromString($decodedContent->jobPayload)
        );
    }
}

$emitter = new Emitter($configuration);

$processingStrategy->process($emitter);

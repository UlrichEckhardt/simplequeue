<?php

/**
 * directory watcher based on Linux inotify
 *
 * See
 * - https://www.php.net/manual/en/book.inotify.php
 */
declare(strict_types=1);

namespace simpleQueue\Example;

use Exception;
use Generator;
use IteratorAggregate;

/**
 * A simple inotify wrapper
 *
 * This is supposed to ease resource management a bit and remove the
 * error checking code from the application logic.
 *
 * @psalm-type InotifyEvent = array{
 *     wd: int,
 *     mask: int,
 *     cookie: int,
 *     name: string
 * }
 *
 * @template-implements IteratorAggregate<InotifyEvent>
 */
class InotifyWatch implements IteratorAggregate
{
    /** @var resource */
    private $inotify;

    public function __construct()
    {
        if (! extension_loaded('inotify')) {
            throw new Exception('inotify extension is not loaded');
        }

        $inotify = inotify_init();
        if ($inotify === false) {
            throw new Exception('inotify_init() failed');
        }
        $this->inotify = $inotify;
    }

    public function __destruct()
    {
        if (is_resource($this->inotify)) {
            fclose($this->inotify);
        }
    }

    /**
     * add a path with event types to watch
     */
    public function watch(string $path, int $mask): void
    {
        $watch = inotify_add_watch(
            $this->inotify,
            $path,
            $mask
        );
        if ($watch === false) {
            throw new Exception('inotify_add_watch() failed');
        }
    }

    /**
     * @return Generator<InotifyEvent>
     */
    public function getIterator(): Generator
    {
        while (true) {
            $events = inotify_read($this->inotify);
            if ($events === false) {
                throw new Exception('inotify_read() failed');
            }
            foreach ($events as $event) {
                yield $event;
            }
        }
    }
}

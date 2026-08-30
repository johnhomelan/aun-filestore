<?php

/*
 * Ahead-of-time replacements for the pcntl_* functions the server uses,
 * implemented in pcntl_posix.cc as thin calls onto the POSIX C library
 * (fork(2), sigaction(2)).
 *
 * Only pcntl_* is shimmed: the libphp TypePHP links has the `posix` extension
 * (so posix_getuid / posix_seteuid work natively and must NOT be redefined) but
 * not `pcntl`.
 *
 * TypePHP's .stub.php files only declare the Zend ABI symbols provided by the
 * accompanying C++; the bodies are intentionally empty. This file is compile
 * only (referenced from project.yml, never autoloaded), so it does not conflict
 * with the real pcntl extension under a normal PHP run.
 *
 * NOTE: pcntl_signal() here just records deliveries; the handlers run when
 * pcntl_signal_dispatch() is called (as with PHP's own non-async pcntl). The
 * server's own code no longer registers pcntl signal handlers, but ReactPHP's
 * StreamSelectLoop does once $loop->addSignal() is used.
 *
 * pcntl_async_signals() is deliberately NOT provided: ReactPHP's
 * StreamSelectLoop checks for it and, when absent, runs in "poll" mode where it
 * calls pcntl_signal_dispatch() every loop tick - exactly the model this shim
 * supports. A real async handler (C -> Zend) is not safe from a signal handler.
 *
 * pcntl_signal() also accepts SIG_DFL / SIG_IGN (ints), which is how
 * StreamSelectLoop unregisters a listener.
 */

function pcntl_fork(): int {}

function pcntl_signal(int $signo, mixed $handler): bool {}

function pcntl_signal_dispatch(): bool {}

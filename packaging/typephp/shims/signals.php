<?php

/*
 * POSIX signal numbers (Linux / glibc values).
 *
 * The pcntl extension normally defines these; TypePHP builds link a libphp that
 * does not have pcntl, so this compile-only file (listed in project.yml, never
 * autoloaded) supplies them. It is never loaded by a normal PHP run, so it can
 * never clash with the real pcntl constants.
 */

const SIGHUP  = 1;
const SIGINT  = 2;
const SIGQUIT = 3;
const SIGILL  = 4;
const SIGABRT = 6;
const SIGFPE  = 8;
const SIGKILL = 9;
const SIGUSR1 = 10;
const SIGSEGV = 11;
const SIGUSR2 = 12;
const SIGPIPE = 13;
const SIGALRM = 14;
const SIGTERM = 15;
const SIGCHLD = 17;
const SIGCONT = 18;
const SIGSTOP = 19;
const SIGTSTP = 20;

// Special "handlers" accepted by pcntl_signal() (ReactPHP's StreamSelectLoop
// passes SIG_DFL when removing a signal listener).
const SIG_DFL = 0;
const SIG_IGN = 1;

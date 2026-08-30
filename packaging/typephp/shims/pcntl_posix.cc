// Thin POSIX-C implementations of the pcntl_* functions the server uses, for
// TypePHP builds (the linked libphp has the `posix` extension but not `pcntl`).
//
// Exposed to PHP by the matching declarations in pcntl_posix.stub.php:
//   php_<name>(...)  in C++   <->   <name>(...)  in PHP
//
// See pcntl_posix.stub.php for the signal-handling caveat.

#include <phpx.h>
#include <phpx_func.h>

#include <unistd.h>
#include <sys/types.h>
#include <csignal>
#include <cstring>
#include <map>

using namespace php;

// --- fork ------------------------------------------------------------

Int php_pcntl_fork()
{
    return static_cast<Int>(::fork());
}

// --- signals -----------------------------------------------------------
//
// A C signal handler must not call into the Zend runtime, so the trampoline
// only latches the signal number. The stored PHP callables are invoked from
// php_pcntl_signal_dispatch(), which the caller runs at a safe point.

namespace {

std::map<int, Variant>       g_handlers;
volatile sig_atomic_t        g_pending[NSIG] = {0};

void aun_signal_trampoline(int signo)
{
    if (signo > 0 && signo < NSIG) {
        g_pending[signo] = 1;
    }
}

} // namespace

Bool php_pcntl_signal(Int signo, Variant handler)
{
    const int s = static_cast<int>(signo);
    if (s <= 0 || s >= NSIG) {
        return false;
    }

    struct sigaction sa;
    std::memset(&sa, 0, sizeof(sa));
    sigemptyset(&sa.sa_mask);

    // SIG_DFL (0) / SIG_IGN (1): drop any stored callable and set the raw
    // disposition. ReactPHP uses pcntl_signal($sig, SIG_DFL) to unregister.
    if (handler.isInt()) {
        g_handlers.erase(s);
        sa.sa_handler = (static_cast<long>(handler.toInt()) == 1) ? SIG_IGN : SIG_DFL;
        return ::sigaction(s, &sa, nullptr) == 0;
    }

    g_handlers[s] = handler; // hold a reference so the callable is not collected
    sa.sa_handler = aun_signal_trampoline;
    sa.sa_flags = SA_RESTART;

    return ::sigaction(s, &sa, nullptr) == 0;
}

Bool php_pcntl_signal_dispatch()
{
    for (auto &entry : g_handlers) {
        const int s = entry.first;
        if (s > 0 && s < NSIG && g_pending[s]) {
            g_pending[s] = 0;
            entry.second({static_cast<Int>(s)}); // handler(signo)
        }
    }
    return true;
}

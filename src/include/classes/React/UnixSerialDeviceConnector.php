<?php

namespace HomeLan\FileStore\React; 

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Socket\ConnectorInterface;
use React\Socket\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Unix domain socket connector
 *
 * Unix domain sockets use atomic operations, so we can as well emulate
 * async behavior.
 */
final class UnixSerialDeviceConnector implements ConnectorInterface
{
    private $loop;

    public function __construct(LoopInterface $loop = null)
    {
        $this->loop = $loop ?: Loop::get();
    }

    public function connect($path)
    {
        if (\strpos($path, '://') === false) {
            $path = 'file://' . $path;
        } elseif (\substr($path, 0, 7) !== 'file://') {
            return Promise\reject(new \InvalidArgumentException(
                'Given URI "' . $path . '" is invalid (EINVAL)',
                 22
            ));
        }

        $resource = @\fopen($path, "w+b");

        if (!$resource) {
            return Promise\reject(new \RuntimeException(
                'Unable to open unix device "' . $path ,
                1
            ));
        }

        $devicePath = \substr($path, 7); // strip 'file://'
        try {
            $this->initSerialPort($devicePath);
        } catch (\RuntimeException $e) {
            \fclose($resource);
            return Promise\reject($e);
        }

        $connection = new Connection($resource, $this->loop);

        return Promise\resolve($connection);
    }

    private function initSerialPort(string $devicePath): void
    {
        // Configure the serial device to match the piconet Node.js driver:
        // 115200 baud, raw 8N1, no echo. PHP exposes no native termios API,
        // so we call libc directly via FFI instead of shelling out to stty.
        //
        // We open a second fd here rather than reusing the fopen() stream
        // because PHP does not expose the underlying file descriptor of a
        // stream resource. A second fd is safe: termios settings are stored
        // on the kernel tty struct, not on the fd, so configuring through any
        // fd to the same device takes effect for all open handles — which is
        // exactly what stty does internally.
        //
        // O_NOCTTY prevents this temporary open from accidentally acquiring
        // the tty as the process's controlling terminal. O_NONBLOCK prevents
        // open() from blocking on devices that gate on carrier-detect (CD);
        // USB CDC devices like the Pico don't assert CD, so without it open()
        // would block indefinitely on some kernels.
        //
        // Linux/glibc ABI constants:
        //   O_RDWR     = 2
        //   O_NOCTTY   = 0400  octal = 256
        //   O_NONBLOCK = 04000 octal = 2048
        //   B115200    = 010002 octal = 4098
        //   TCSANOW    = 0  (apply immediately, don't wait for drain)
        //
        // struct termios follows the glibc ABI (NCCS = 32). The compiler
        // inserts 3 bytes of padding before c_ispeed to keep it 4-byte
        // aligned after the 1-byte c_line + 32-byte c_cc[32], giving a
        // total struct size of 60 bytes matching sizeof(struct termios) on
        // Linux x86-64. glibc's tcgetattr/tcsetattr translate between this
        // layout and the smaller kernel struct internally.
        $ffi = \FFI::cdef('
            typedef unsigned int  speed_t;
            typedef unsigned int  tcflag_t;
            typedef unsigned char cc_t;

            struct termios {
                tcflag_t c_iflag;
                tcflag_t c_oflag;
                tcflag_t c_cflag;
                tcflag_t c_lflag;
                cc_t     c_line;
                cc_t     c_cc[32];
                speed_t  c_ispeed;
                speed_t  c_ospeed;
            };

            int  open(const char *pathname, int flags);
            int  close(int fd);
            int  tcgetattr(int fd, struct termios *termios_p);
            int  tcsetattr(int fd, int optional_actions, const struct termios *termios_p);
            int  cfsetispeed(struct termios *termios_p, speed_t speed);
            int  cfsetospeed(struct termios *termios_p, speed_t speed);
            void cfmakeraw(struct termios *termios_p);
        ', 'libc.so.6');

        $fd = $ffi->open($devicePath, 2 | 256 | 2048);
        if ($fd < 0) {
            throw new \RuntimeException(
                'Failed to open serial port "' . $devicePath . '" for configuration'
            );
        }

        try {
            $termios = $ffi->new('struct termios');

            if ($ffi->tcgetattr($fd, \FFI::addr($termios)) !== 0) {
                throw new \RuntimeException('tcgetattr failed on "' . $devicePath . '"');
            }

            // cfmakeraw is the canonical glibc way to request raw mode. It clears
            // all input/output processing flags in one call — no CR/LF translation,
            // no signal generation, no echo — so the piconet text protocol passes
            // through the kernel line discipline unmodified.
            $ffi->cfmakeraw(\FFI::addr($termios));
            $ffi->cfsetispeed(\FFI::addr($termios), 4098);
            $ffi->cfsetospeed(\FFI::addr($termios), 4098);

            if ($ffi->tcsetattr($fd, 0, \FFI::addr($termios)) !== 0) {
                throw new \RuntimeException('tcsetattr failed on "' . $devicePath . '"');
            }
        } finally {
            $ffi->close($fd);
        }
    }
}

/* netio.h - low-level Econet primitives (OSWORD &10/&11, OSBYTE &32/&33/&34)
 * for the SqlServer sample client (see sqlcli.c).
 *
 * Mirrors bbc-tests/SQLCLI.BBC's FNtx/FNrxopen/FNrx/FNrxread/PROCrxkill
 * exactly (same control block byte layouts, adapted from J.G.Harston's
 * BLib.Net library and the OSWORD &10/&11 reference at
 * https://mdfs.net/Docs/Comp/BBC/Oswords).
 *
 * tx_send()/rx_open()/rx_poll()/rx_read()/rx_kill()/delay_approx() are
 * ordinary C functions (see netio.c), built on top of vbcc's own bbc.h
 * (shipped with the 6502-bbc target - see the Makefile), which supplies
 * OSWORD()/OSBYTE1()/OSBYTE1NR() as tiny functions whose body is a literal
 * assembly string - vbcc's equivalent of inline asm, the same mechanism
 * bbc.h itself is written with.
 *
 * bbc_putchar()/bbc_getch() are that same direct-inline-asm mechanism,
 * used here instead of bbc.h's own OSBYTE-based helpers since raw OSWRCH/
 * OSRDCH is simpler than any of the calls bbc.h predefines. They're
 * declared with their asm bodies right here (not in netio.c) deliberately:
 * vbcc only emits an asm-bodied function into the object file of whichever
 * translation unit actually calls it (unused ones are dropped silently,
 * with no exported symbol at all - confirmed by inspecting netio.o), so
 * putting them in the header lets sqlcli.c (the only caller) pick up its
 * own compiled copy, the same way every .c file including bbc.h gets its
 * own copies of OSWORD/OSBYTE.
 */
#ifndef NETIO_H
#define NETIO_H

/* Transmit control block for OSWORD &10 - fill in, then call tx_send(). */
typedef struct {
    unsigned char ctrl;        /* offset 0: Econet control byte (bit 7 set), e.g. 0x80 */
    unsigned char port;        /* offset 1 */
    unsigned char stn;         /* offset 2 */
    unsigned char net;         /* offset 3 */
    unsigned int  addr;        /* offset 4-5 */
    unsigned int  addr_hi;     /* offset 6-7: always 0 (addresses are 16-bit) */
    unsigned int  addr_end;    /* offset 8-9 */
    unsigned int  addr_end_hi; /* offset 10-11: always 0 */
    unsigned long reserved;    /* offset 12-15: always 0 */
} TxBlock;

extern TxBlock txblk;

/* Receive control block for OSWORD &11 - shared by rx_open()/rx_read(). */
typedef struct {
    unsigned char handle;      /* offset 0: 0 to open, or the handle to read/close */
    unsigned char mask;        /* offset 1: 0x7F - fixed, matches SQLCLI.BBC's FNrxopen */
    unsigned char port;        /* offset 2 */
    unsigned char stn;         /* offset 3 */
    unsigned char stn_hi;      /* offset 4: always 0 */
    unsigned int  addr;        /* offset 5-6 */
    unsigned int  addr_hi;     /* offset 7-8: always 0 */
    unsigned int  addr_end;    /* offset 9-10: buffer end on entry to rx_open()/rx_read(); actual end on return */
    unsigned int  addr_end_hi; /* offset 11-12: always 0 */
} RxBlock;

extern RxBlock rxblk;

/* Sends txblk (already filled in) with the given retry-on-busy behaviour
 * FNtx uses. Returns 0 on success, else an Econet error code.
 */
unsigned char tx_send(void);

/* Opens a receive block listening on rxblk.port for packets from
 * rxblk.stn, rxblk.addr..rxblk.addr_end (already filled in). Returns the
 * block handle, or 0 if none are free.
 */
unsigned char rx_open(void);

/* Non-blocking poll: nonzero if the receive block has data waiting. */
unsigned char rx_poll(unsigned char rxnum);

/* Reads and frees receive block rxnum (rxblk.addr/addr_end must already
 * be set to the buffer to read into). Returns the actual end address
 * (rxblk.addr_end after the call) - subtract rxblk.addr from it yourself
 * for a byte count, since rxblk.addr may already have changed by the time
 * you'd otherwise read it back.
 */
unsigned int rx_read(unsigned char rxnum);

/* Abandons a receive block that was opened but never triggered. */
void rx_kill(unsigned char rxnum);

/* Approximate busy-wait delay in centiseconds - NOT based on the real
 * system clock (that needs OSWORD &01, whose exact layout wasn't
 * re-verified for this sample - see netio.c) - just a rough CPU busy
 * loop, good enough for tx_send()'s brief "line busy" backoff.
 */
void delay_approx(unsigned char cs);

/* Direct OSWRCH/OSRDCH console I/O - see the header comment above for why
 * these are declared (with their asm bodies) here rather than in netio.c.
 */
void          bbc_putchar(__reg("a") unsigned char c) = "\tjsr\t$ffee";
unsigned char bbc_getch(void) = "\tjsr\t$ffe0";

#endif

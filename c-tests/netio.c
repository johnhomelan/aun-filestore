/* netio.c - low-level Econet primitives for the SqlServer sample client.
 * See netio.h for the C-visible declarations and docs/protocols/sql-server.md
 * for the wire protocol these serve. Byte layouts and retry logic are a
 * direct port of bbc-tests/SQLCLI.BBC's FNtx/FNrxopen/FNrx/FNrxread/
 * PROCrxkill (themselves adapted from J.G.Harston's BLib.Net library and
 * the OSWORD &10/&11 reference at https://mdfs.net/Docs/Comp/BBC/Oswords).
 *
 * OSWORD()/OSBYTE1()/OSBYTE1NR() (called below) come from bbc.h, shipped
 * with vbcc's 6502-bbc target (see the Makefile) - each is a tiny function
 * whose body is a literal assembly string (vbcc's inline-asm mechanism):
 *   OSWORD(a, ptr)   -> A=a, X/Y=ptr low/high, JSR &FFF1
 *   OSBYTE1(a, x)    -> A=a, X=x, JSR &FFF4, returns the X OSBYTE leaves on exit
 *   OSBYTE1NR(a, x)  -> same, but doesn't wait for/return a result
 * matching the plain "lda #n / ldx op / jsr $fffX" shape SQLCLI.BBC's own
 * CALL/USR-based FNtx/FNrxopen use.
 */
#include "bbc.h"
#include "netio.h"

TxBlock txblk;
RxBlock rxblk;

/* ---------------------------------------------------------------
 * unsigned char tx_send(void)
 * Sends txblk (already filled in by the caller), retrying up to 5 times
 * on a "line busy" Econet error (&41/&42) with a brief delay between
 * tries, matching FNtx. Returns 0 on success, else an Econet error code.
 * ---------------------------------------------------------------*/
unsigned char tx_send(void)
{
    unsigned char origctrl = txblk.ctrl;
    unsigned char tries = 5;
    unsigned char err;

    for (;;) {
        do {
            txblk.ctrl = origctrl;
            OSWORD(0x10, &txblk);
        } while (txblk.ctrl == 0);

        do {
            err = OSBYTE1(0x32, 0);
        } while (err >= 0x80);

        if (err != 0x41 && err != 0x42) return err;
        if (--tries == 0) return err;
        delay_approx(50);
    }
}

/* ---------------------------------------------------------------
 * unsigned char rx_open(void)
 * Opens a receive block using rxblk (mask/port/stn/addr/addr_end already
 * filled in by the caller). Returns the block handle, or 0 if none free.
 * ---------------------------------------------------------------*/
unsigned char rx_open(void)
{
    rxblk.handle = 0;
    OSWORD(0x11, &rxblk);
    return rxblk.handle;
}

/* ---------------------------------------------------------------
 * unsigned char rx_poll(unsigned char rxnum)
 * Non-blocking poll: returns nonzero if the receive block has data
 * waiting.
 * ---------------------------------------------------------------*/
unsigned char rx_poll(unsigned char rxnum)
{
    return OSBYTE1(0x33, rxnum) & 0x80;
}

/* ---------------------------------------------------------------
 * unsigned int rx_read(unsigned char rxnum)
 * Reads and frees receive block rxnum (rxblk.addr/addr_end must already
 * be set to the buffer to read into). Returns the actual end address
 * (rxblk.addr_end after the call).
 * ---------------------------------------------------------------*/
unsigned int rx_read(unsigned char rxnum)
{
    rxblk.handle = rxnum;
    OSWORD(0x11, &rxblk);
    return rxblk.addr_end;
}

/* ---------------------------------------------------------------
 * void rx_kill(unsigned char rxnum)
 * Abandons a receive block that was opened but never triggered.
 * ---------------------------------------------------------------*/
void rx_kill(unsigned char rxnum)
{
    OSBYTE1NR(0x34, rxnum);
}

/* ---------------------------------------------------------------
 * void delay_approx(unsigned char cs)
 * A rough CPU busy-wait, NOT calibrated against the real system clock
 * (that needs OSWORD &01, whose exact layout wasn't re-verified for this
 * sample) - just short enough to space out retries a little, long enough
 * to be pointless to make precise for that purpose.
 * ---------------------------------------------------------------*/
void delay_approx(unsigned char cs)
{
    unsigned int i;
    while (cs--) {
        for (i = 0; i < 3000; i++) { }
    }
}

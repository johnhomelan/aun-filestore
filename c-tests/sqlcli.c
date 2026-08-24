/* sqlcli.c - SqlServer sample client (see docs/protocols/sql-server.md and
 * Services/Provider/SqlServer.php), for a BBC Micro, cross-compiled with
 * vbcc (see the Makefile in this directory, which builds via the
 * bbc-micro-build-env podman image).
 *
 * Same functional shape as bbc-tests/SQLCLI.BBC (LOGIN, parameterised
 * CREATE/INSERT/UPDATE/DELETE via sql_exec(), and a paged, parameterised
 * SELECT via sql_select()) - see that file's header comment for the
 * paging design rationale (rows are buffered as they stream in and only
 * *displayed* a page at a time; the wire-level flow control is the
 * ordinary Econet receive ack, not anything this client throttles).
 * netio.c/netio.h carry the low-level OSWORD/OSBYTE primitives.
 *
 * Built and linked against vbcc's own 6502-bbc target (see the Makefile) -
 * unlike cc65, whose "bbc" target ships only a linker memory map with no
 * crt0/library/headers, vbcc's bbc-micro-build-env image ships a complete,
 * working BBC target (crt0, a real C library including stdio, bbc.h) - so
 * this links normally, with no cc65-style "none platform" workaround
 * needed.
 */
#include <string.h>
#include <stdio.h>
#include "netio.h"

/* --- Configuration --- */
static unsigned char sql_net  = 0;     /* network SqlServer is on (0 = local) */
static unsigned char sql_stn  = 254;   /* station running SqlServer's control port */
static unsigned char sql_port = 0xB7;  /* SqlServer's Econet control port */
static unsigned char my_stream_port = 0x50; /* port this station listens on for streamed results */
#define PAGE_SIZE 10   /* rows displayed per page by show_page() */
#define MAXROWS   50   /* safety cap on rows buffered client-side - see the header comment */
#define MAXCOLS   8

/* --- Operation codes (docs/protocols/sql-server.md) --- */
#define OP_LOGIN          1
#define OP_LOGOUT         2
#define OP_LIST_DATABASES 3
#define OP_QUERY          4
#define OP_CANCEL         5

/* --- Value wire-format type tags (used both directions) --- */
#define TAG_NULL    0
#define TAG_INTEGER 1
#define TAG_FLOAT   2
#define TAG_TEXT    3
#define TAG_BLOB    4

#define EOF_COMPLETE 0x80

/* --- Buffers --- */
static unsigned char txbuf[512];
static unsigned int  tx_len;
static unsigned char rxbuf[260];
static unsigned char rxbuf2[260];
static unsigned char accbuf[512];
static unsigned int  acc_len;

/* --- Parameter binding scratch --- */
#define MAXPARAMS 4
static unsigned char p_type[MAXPARAMS];
static long          p_int[MAXPARAMS];
static const char    *p_text[MAXPARAMS];
static unsigned char p_count;

/* --- Query result state --- */
static unsigned char g_status;
static unsigned char g_flag;
static long          g_rows_affected;
static char          g_errmsg[64];

/* --- Row buffer (paging) --- */
static char row_text[MAXROWS][80];
static unsigned int row_count;

/* --- Console output --- */
static char outbuf[80];

/* bbc_print() rather than printf(): see netio.h/netio.s - "none", the
 * platform library this links against, has no working console driver, so
 * all output goes through bbc_putchar() (direct OSWRCH) instead. sprintf()
 * itself is unaffected (pure string formatting, no device involved) and
 * is used freely throughout to build the line first.
 */
static void bbc_print(const char *s)
{
    while (*s) {
        if (*s == '\n') bbc_putchar(13); else bbc_putchar((unsigned char) *s);
        s++;
    }
}

/* -----------------------------------------------------------------------
 * Little-endian helpers - reading/writing the wire format's multi-byte
 * fields. Ordinary C (no assembler needed - cc65 already generates
 * perfectly good 6502 code for this from plain C, unlike the BBC BASIC
 * client, which had no native 32-bit/64-bit integer support to lean on).
 * ---------------------------------------------------------------------*/
static unsigned int get_u16le(const unsigned char *p)
{
    return (unsigned int) p[0] | ((unsigned int) p[1] << 8);
}

static void put_u16le(unsigned char *p, unsigned int v)
{
    p[0] = (unsigned char) v;
    p[1] = (unsigned char) (v >> 8);
}

static long get_i32le(const unsigned char *p)
{
    unsigned long u = (unsigned long) p[0]
        | ((unsigned long) p[1] << 8)
        | ((unsigned long) p[2] << 16)
        | ((unsigned long) p[3] << 24);
    return (long) u;
}

/* Reads the low 4 bytes of an 8-byte little-endian signed integer at p,
 * and reports (via *overflow) whether the high 4 bytes are a valid
 * sign-extension of it - i.e. whether the true 64-bit value actually fits
 * a 32-bit long. cc65's long is 32-bit; there is no 64-bit integer type
 * to hold the wire format's INTEGER value exactly, so this is the
 * accuracy this sample settles for (ample for demo-sized ids/counts).
 */
static long get_i64le_as_i32(const unsigned char *p, unsigned char *overflow)
{
    long lo = get_i32le(p);
    unsigned char hi4 = p[4], hi5 = p[5], hi6 = p[6], hi7 = p[7];
    if (lo < 0) {
        *overflow = !(hi4 == 0xFF && hi5 == 0xFF && hi6 == 0xFF && hi7 == 0xFF);
    } else {
        *overflow = !(hi4 == 0 && hi5 == 0 && hi6 == 0 && hi7 == 0);
    }
    return lo;
}

static void put_i32_as_i64le(unsigned char *p, long v)
{
    unsigned char hi = (v < 0) ? 0xFF : 0x00;
    p[0] = (unsigned char) v;
    p[1] = (unsigned char) (v >> 8);
    p[2] = (unsigned char) (v >> 16);
    p[3] = (unsigned char) (v >> 24);
    p[4] = hi; p[5] = hi; p[6] = hi; p[7] = hi;
}

/* -----------------------------------------------------------------------
 * Parameter binding
 * ---------------------------------------------------------------------*/
static void clear_params(void)      { p_count = 0; }
static void bind_null(void)         { p_type[p_count] = TAG_NULL; p_count++; }
static void bind_int(long v)        { p_type[p_count] = TAG_INTEGER; p_int[p_count] = v; p_count++; }
static void bind_text(const char *s){ p_type[p_count] = TAG_TEXT; p_text[p_count] = s; p_count++; }

/* -----------------------------------------------------------------------
 * Wire encoding - QUERY/LOGIN payload building into txbuf[]/tx_len.
 * ---------------------------------------------------------------------*/
static void put_lenstr(const char *s)
{
    unsigned char n = (unsigned char) strlen(s);
    txbuf[tx_len++] = n;
    memcpy(&txbuf[tx_len], s, n);
    tx_len += n;
}

static void put_null(void)  { txbuf[tx_len++] = TAG_NULL; }

static void put_int(long v)
{
    txbuf[tx_len++] = TAG_INTEGER;
    put_i32_as_i64le(&txbuf[tx_len], v);
    tx_len += 8;
}

static void put_text(const char *s)
{
    unsigned int n = (unsigned int) strlen(s);
    txbuf[tx_len++] = TAG_TEXT;
    put_u16le(&txbuf[tx_len], n);
    tx_len += 2;
    memcpy(&txbuf[tx_len], s, n);
    tx_len += n;
}

static void build_login(const char *user, const char *pass)
{
    txbuf[0] = OP_LOGIN;
    tx_len = 1;
    put_lenstr(user);
    put_lenstr(pass);
}

static void build_query(const char *db, const char *sql)
{
    unsigned char i;
    unsigned int sqllen;

    txbuf[0] = OP_QUERY;
    tx_len = 1;
    put_lenstr(db);
    put_u16le(&txbuf[tx_len], my_stream_port);
    tx_len += 2;
    txbuf[tx_len++] = p_count;
    for (i = 0; i < p_count; i++) {
        if (p_type[i] == TAG_NULL)    put_null();
        if (p_type[i] == TAG_INTEGER) put_int(p_int[i]);
        if (p_type[i] == TAG_TEXT)    put_text(p_text[i]);
    }
    sqllen = (unsigned int) strlen(sql);
    memcpy(&txbuf[tx_len], sql, sqllen);
    tx_len += sqllen;
}

/* -----------------------------------------------------------------------
 * Sending a request and waiting for its (control-port) reply - shared by
 * LOGIN/LOGOUT/CANCEL and the QUERY immediate reply.
 * ---------------------------------------------------------------------*/
static void set_reply_error(unsigned char *buf, unsigned int len)
{
    unsigned int i, n = 0;
    for (i = 1; i < len && buf[i] != 13 && n < sizeof(g_errmsg) - 1; i++) {
        g_errmsg[n++] = (char) buf[i];
    }
    g_errmsg[n] = '\0';
}

/* Sends whatever is in txbuf[0..tx_len) to the control port and waits
 * (with a timeout) for its reply into buf. Returns the reply length, or 0
 * on any failure (no free receive block, transmit error, timeout).
 */
static unsigned int send_and_wait(unsigned char *buf, unsigned int buflen)
{
    unsigned char rxnum;
    unsigned char tries;
    unsigned int len;

    rxblk.handle = 0;
    rxblk.mask = 0x7F;
    rxblk.port = sql_port;
    rxblk.stn = sql_stn;
    rxblk.stn_hi = 0;
    rxblk.addr = (unsigned int) buf;
    rxblk.addr_hi = 0;
    rxblk.addr_end = (unsigned int) buf + buflen;
    rxblk.addr_end_hi = 0;
    rxnum = rx_open();
    if (rxnum == 0) {
        strcpy(g_errmsg, "no receive blocks free");
        return 0;
    }

    txblk.ctrl = 0x80;
    txblk.port = sql_port;
    txblk.stn = sql_stn;
    txblk.net = sql_net;
    txblk.addr = (unsigned int) txbuf;
    txblk.addr_hi = 0;
    txblk.addr_end = (unsigned int) txbuf + tx_len;
    txblk.addr_end_hi = 0;
    txblk.reserved = 0;
    if (tx_send() != 0) {
        strcpy(g_errmsg, "Econet transmit failed");
        rx_kill(rxnum);
        return 0;
    }

    /* Polling with a rough tries-based timeout rather than the real clock
     * - see delay_approx()'s own comment on why this sample doesn't read
     * OSWORD &01.
     */
    for (tries = 0; tries < 200; tries++) {
        if (rx_poll(rxnum)) {
            len = rx_read(rxnum) - (unsigned int) buf;
            return len;
        }
        delay_approx(2);
    }
    strcpy(g_errmsg, "timed out");
    rx_kill(rxnum);
    return 0;
}

static unsigned char simple_op(unsigned char op)
{
    unsigned int len;
    txbuf[0] = op;
    tx_len = 1;
    len = send_and_wait(rxbuf, sizeof(rxbuf));
    if (len == 0) return 0;
    return rxbuf[0] == 0;
}

unsigned char sql_login(const char *user, const char *pass)
{
    unsigned int len;
    build_login(user, pass);
    len = send_and_wait(rxbuf, sizeof(rxbuf));
    if (len == 0) return 0;
    g_status = rxbuf[0];
    if (g_status != 0) {
        set_reply_error(rxbuf, len);
        return 0;
    }
    return 1;
}

void sql_logout(void)  { simple_op(OP_LOGOUT); }
void sql_cancel(void)  { simple_op(OP_CANCEL); }

/* -----------------------------------------------------------------------
 * FNsendquery equivalent - builds+sends a QUERY, decodes the immediate
 * reply into g_status/g_flag/g_rows_affected/g_errmsg.
 * ---------------------------------------------------------------------*/
static unsigned char send_query(const char *db, const char *sql)
{
    unsigned int len;
    build_query(db, sql);
    len = send_and_wait(rxbuf, sizeof(rxbuf));
    if (len == 0) return 0;
    g_status = rxbuf[0];
    if (g_status != 0) {
        set_reply_error(rxbuf, len);
        return 0;
    }
    g_flag = rxbuf[1];
    if (g_flag == 0) {
        g_rows_affected = get_i32le(&rxbuf[2]);
    }
    return 1;
}

/* -----------------------------------------------------------------------
 * Result streaming and paging - see bbc-tests/SQLCLI.BBC's own header
 * comment for the design rationale (identical here): a result set
 * arrives on my_stream_port as a continuous byte stream with no relation
 * to 256-byte Econet block boundaries, and the only "ack" this protocol
 * has is the ordinary Econet receive itself - so this client always keeps
 * a receive block open and reads every block promptly, decoding whatever
 * complete values it can and carrying over the rest, buffering decoded
 * rows and only *displaying* them a page at a time.
 * ---------------------------------------------------------------------*/
static void carry(unsigned int off)
{
    unsigned int remain = acc_len - off;
    if (off > 0 && remain > 0) memmove(accbuf, &accbuf[off], remain);
    acc_len = remain;
}

static unsigned char try_header(unsigned int *off, int *colcount)
{
    unsigned int start = *off, o = *off, i, namelen, n;
    if (acc_len - o < 2) { *colcount = -1; return 0; }
    n = get_u16le(&accbuf[o]); o += 2;
    for (i = 0; i < n; i++) {
        if (acc_len - o < 1) { *off = start; *colcount = -1; return 0; }
        namelen = accbuf[o]; o += 1;
        if (acc_len - o < namelen) { *off = start; *colcount = -1; return 0; }
        o += namelen;
    }
    *off = o;
    *colcount = (int) n;
    return 1;
}

/* Decodes one tag+value at *off into cell (a small fixed-size buffer),
 * returning 0 (leaving *off unchanged) if the whole value isn't available
 * yet.
 */
static unsigned char try_cell(unsigned int *off, char *cell, unsigned int celllen)
{
    unsigned int start = *off, o = *off, n, i;
    unsigned char tag, overflow;
    long v;

    if (acc_len - o < 1) return 0;
    tag = accbuf[o];

    if (tag == TAG_NULL) {
        strcpy(cell, "NULL");
        *off = o + 1;
        return 1;
    }
    if (tag == TAG_INTEGER) {
        if (acc_len - (o + 1) < 8) return 0;
        v = get_i64le_as_i32(&accbuf[o + 1], &overflow);
        if (overflow) strcpy(cell, "<int overflow>"); else sprintf(cell, "%ld", v);
        *off = o + 9;
        return 1;
    }
    if (tag == TAG_FLOAT) {
        /* Not decoded to a real value in this sample - see the header
         * comment on cc65's lack of a 64-bit type; shown as a marker
         * instead of pretending to a precision this client can't provide.
         */
        if (acc_len - (o + 1) < 8) return 0;
        strcpy(cell, "<float>");
        *off = o + 9;
        return 1;
    }
    if (tag == TAG_TEXT || tag == TAG_BLOB) {
        if (acc_len - (o + 1) < 2) return 0;
        n = get_u16le(&accbuf[o + 1]);
        if (acc_len - (o + 3) < n) return 0;
        if (n > celllen - 1) n = celllen - 1;
        for (i = 0; i < n; i++) cell[i] = (char) accbuf[o + 3 + i];
        cell[n] = '\0';
        *off = o + 3 + get_u16le(&accbuf[start + 1]);
        return 1;
    }
    sprintf(cell, "<bad tag %d>", (int) tag);
    *off = o + 1;
    return 1;
}

static unsigned char try_row(unsigned int *off, int colcount)
{
    unsigned int start = *off;
    int i;
    char cell[40];
    char line[80];
    line[0] = '\0';
    for (i = 0; i < colcount; i++) {
        if (!try_cell(off, cell, sizeof(cell))) { *off = start; return 0; }
        if (i > 0) strcat(line, "\t");
        strcat(line, cell);
    }
    if (row_count < MAXROWS) {
        strncpy(row_text[row_count], line, sizeof(row_text[0]) - 1);
        row_text[row_count][sizeof(row_text[0]) - 1] = '\0';
        row_count++;
    }
    return 1;
}

static void decode_available(int *colcount, unsigned char capped)
{
    unsigned int off = 0;
    if (*colcount == -1) {
        if (!try_header(&off, colcount)) { carry(off); return; }
    }
    for (;;) {
        if (capped) { carry(off); return; }
        if (!try_row(&off, *colcount)) { carry(off); return; }
    }
}

static void append_received(unsigned char rxnum)
{
    unsigned int got = rx_read(rxnum) - (unsigned int) rxbuf;
    memcpy(&accbuf[acc_len], rxbuf, got);
    acc_len += got;
}

static void print_finished(unsigned char *buf)
{
    unsigned char eofflag = buf[1];
    long rows = get_i32le(&buf[2]);
    if (eofflag == EOF_COMPLETE) {
        sprintf(outbuf, "  (complete - %ld row(s))\n", rows);
    } else {
        sprintf(outbuf, "  (stream ended with an error after %ld row(s))\n", rows);
    }
    bbc_print(outbuf);
}

static void show_page(void)
{
    unsigned int i = 0, shown;
    char key;
    if (row_count == 0) { bbc_print("  (no rows)\n"); return; }
    do {
        shown = 0;
        do {
            bbc_print(row_text[i]);
            bbc_putchar(13);
            i++; shown++;
        } while (i < row_count && shown < PAGE_SIZE);
        if (i < row_count) {
            bbc_print("-- more (SPACE) or stop (Q) --");
            do { key = (char) bbc_getch(); } while (key != ' ' && key != 'q' && key != 'Q');
            bbc_putchar(13);
        } else {
            key = ' ';
        }
    } while (i < row_count && key != 'q' && key != 'Q');
}

static void drain_results(unsigned char discard)
{
    unsigned char rx_stream, rx_ctrl;
    unsigned char done = 0, capped = 0;
    int colcount = -1;

    acc_len = 0;
    row_count = 0;

    rxblk.mask = 0x7F; rxblk.stn = sql_stn; rxblk.stn_hi = 0;
    rxblk.handle = 0; rxblk.port = my_stream_port;
    rxblk.addr = (unsigned int) rxbuf; rxblk.addr_hi = 0;
    rxblk.addr_end = (unsigned int) rxbuf + sizeof(rxbuf); rxblk.addr_end_hi = 0;
    rx_stream = rx_open();

    rxblk.port = sql_port;
    rxblk.addr = (unsigned int) rxbuf2; rxblk.addr_hi = 0;
    rxblk.addr_end = (unsigned int) rxbuf2 + sizeof(rxbuf2); rxblk.addr_end_hi = 0;
    rx_ctrl = rx_open();

    while (!done) {
        if (rx_stream != 0 && rx_poll(rx_stream)) {
            append_received(rx_stream);
            rxblk.mask = 0x7F; rxblk.stn = sql_stn; rxblk.stn_hi = 0;
            rxblk.handle = 0; rxblk.port = my_stream_port;
            rxblk.addr = (unsigned int) rxbuf; rxblk.addr_hi = 0;
            rxblk.addr_end = (unsigned int) rxbuf + sizeof(rxbuf); rxblk.addr_end_hi = 0;
            rx_stream = rx_open();
        }

        decode_available(&colcount, capped);
        if (colcount != -1 && !capped && row_count >= MAXROWS) capped = 1;

        if (capped) {
            sprintf(outbuf, "  (showing the first %u rows - cancelling the rest)\n", (unsigned int) MAXROWS);
            bbc_print(outbuf);
            if (rx_stream != 0) rx_kill(rx_stream);
            if (rx_ctrl != 0) rx_kill(rx_ctrl);
            sql_cancel();
            done = 1;
        } else if (rx_ctrl != 0 && rx_poll(rx_ctrl)) {
            rx_read(rx_ctrl);
            print_finished(rxbuf2);
            done = 1;
        }
    }

    if (!discard) show_page();
}

/* -----------------------------------------------------------------------
 * Public operations
 * ---------------------------------------------------------------------*/
unsigned char sql_exec(const char *db, const char *sql)
{
    if (!send_query(db, sql)) return 0;
    if (g_flag == 0) return 1;
    bbc_print("  (warning: statement returned a result set - draining and discarding it)\n");
    drain_results(1);
    return 1;
}

void sql_select(const char *db, const char *sql)
{
    if (!send_query(db, sql)) return;
    if (g_flag == 0) {
        sprintf(outbuf, "  (no result set - %ld rows affected)\n", g_rows_affected);
        bbc_print(outbuf);
        return;
    }
    drain_results(0);
}

/* -----------------------------------------------------------------------
 * Demo
 * ---------------------------------------------------------------------*/
int main(void)
{
    char namebuf[24];
    unsigned char i;

    bbc_print("SQLCLI - SqlServer sample client\n");
    sprintf(outbuf, "Connecting to station %u.%u port &%02X\n\n", sql_net, sql_stn, sql_port);
    bbc_print(outbuf);

    bbc_print("Logging in...\n");
    if (!sql_login("JOHN", "secret")) {
        sprintf(outbuf, "Login failed: %s\n", g_errmsg);
        bbc_print(outbuf);
        return 1;
    }
    bbc_print("  OK\n\n");

    bbc_print("Creating table (ignore an error here if it already exists)...\n");
    clear_params();
    sql_exec("widgets", "CREATE TABLE widgets (id INTEGER, name TEXT)");
    bbc_print("\n");

    bbc_print("Inserting rows...\n");
    for (i = 1; i <= 25; i++) {
        sprintf(namebuf, "widget %u", (unsigned int) i);
        clear_params();
        bind_int(i);
        bind_text(namebuf);
        sql_exec("widgets", "INSERT INTO widgets (id, name) VALUES (?, ?)");
    }
    sprintf(outbuf, "  Inserted 25 rows (rows affected on the last insert: %ld)\n\n", g_rows_affected);
    bbc_print(outbuf);

    bbc_print("Updating row 1...\n");
    clear_params();
    bind_text("renamed widget");
    bind_int(1);
    sql_exec("widgets", "UPDATE widgets SET name = ? WHERE id = ?");
    sprintf(outbuf, "  Rows affected: %ld\n\n", g_rows_affected);
    bbc_print(outbuf);

    bbc_print("Deleting row 2...\n");
    clear_params();
    bind_int(2);
    sql_exec("widgets", "DELETE FROM widgets WHERE id = ?");
    sprintf(outbuf, "  Rows affected: %ld\n\n", g_rows_affected);
    bbc_print(outbuf);

    sprintf(outbuf, "Selecting all widgets with id > 0, paged %u at a time...\n", (unsigned int) PAGE_SIZE);
    bbc_print(outbuf);
    bbc_print("(SPACE for next page, Q to stop early)\n\n");
    clear_params();
    bind_int(0);
    sql_select("widgets", "SELECT id, name FROM widgets WHERE id > ? ORDER BY id");

    bbc_print("\nLogging out...\n");
    sql_logout();
    bbc_print("Done.\n");
    return 0;
}

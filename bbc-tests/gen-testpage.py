#!/usr/bin/env python3
"""
ESC/P version of the Fedora printer test page.
Generates testpage.prn in the script's directory.

Targets a 9-pin Epson-compatible printer (FX/RX/LQ series),
A4 paper, 10 CPI, 6 LPI.  Clean binary — no CUPS wrappers.
"""

import datetime, os

ESC = b'\x1b'
CR  = b'\r'
LF  = b'\n'
FF  = b'\x0c'
SI  = b'\x0f'   # condensed on  (~17 CPI)
DC2 = b'\x12'   # condensed off (back to pica)

def init():          return ESC + b'@'
def bold_on():       return ESC + b'E'
def bold_off():      return ESC + b'F'
def italic_on():     return ESC + b'4'
def italic_off():    return ESC + b'5'
def uline_on():      return ESC + b'-\x01'
def uline_off():     return ESC + b'-\x00'
def dstrike_on():    return ESC + b'G'
def dstrike_off():   return ESC + b'H'
def wide_on():       return ESC + b'W\x01'
def wide_off():      return ESC + b'W\x00'
def pica():          return ESC + b'P'   # 10 CPI
def elite():         return ESC + b'M'   # 12 CPI
def micro():         return ESC + b'g'   # 15 CPI
def ls_sixth():      return ESC + b'2'   # 1/6"  line spacing
def ls_n216(n):      return ESC + b'3' + bytes([n])
def nlq():           return ESC + b'x\x01'
def draft():         return ESC + b'x\x00'
def font(n):         return ESC + b'k' + bytes([n])
def color(n):        return ESC + b'r' + bytes([n])

def blank():         return CR + LF
def rule(ch=b'-', w=78): return ch * w + CR + LF

def centre(text, w=78):
    t = text.encode() if isinstance(text, str) else text
    pad = max(0, (w - len(t)) // 2)
    return b' ' * pad + t + CR + LF

def bitrow(cols, fn):
    """One 8-pin single-density (mode 0, 60 dpi) horizontal pass."""
    nL, nH = cols & 0xFF, (cols >> 8) & 0xFF
    data = bytes(fn(c) for c in range(cols))
    return ESC + b'*\x00' + bytes([nL, nH]) + data + CR + LF


# ---------------------------------------------------------------------------
# Panel builders — each returns a function (col) -> byte for one band.
# All panels are 8 bands tall (matching ls_n216(24) = 8/72" per band).
# ---------------------------------------------------------------------------

def panel_freq_sweep(cols=96):
    """Left panel: vertical stripe frequency sweep, doubling each section."""
    def fn(col):
        # 4 sections of 24 cols: period 2,4,8,16 — denser to coarser
        section = col // (cols // 4)
        period  = 2 ** (section + 1)   # 2, 4, 8, 16
        return 0xFF if (col % period) < (period // 2) else 0x00
    return cols, fn

def panel_grey(cols=90):
    """Middle panel: grayscale gradient in 9 equal steps."""
    levels = [0x00, 0x11, 0x22, 0x44, 0x55, 0xAA, 0xBB, 0xDD, 0xFF]
    step   = cols // len(levels)
    def fn(col):
        idx = min(col // step, len(levels) - 1)
        return levels[idx]
    return cols, fn

def panel_checker(cols=72):
    """Right panel: 4×4 dot checkerboard."""
    def fn(col):
        return 0xAA if (col // 4) % 2 == 0 else 0x55
    return cols, fn


# ---------------------------------------------------------------------------
# Colour swatch — 7 solid strips, one per ESC r colour code
# ---------------------------------------------------------------------------
COLOURS      = [0, 1, 2, 3, 4, 5, 6]
COLOUR_NAMES = ['Black', 'Magenta', 'Cyan', 'Violet', 'Yellow', 'Red', 'Green']

def colour_row(d, bands=4, cols_each=10):
    for _ in range(bands):
        for col_code in COLOURS:
            d += color(col_code)
            nL = cols_each & 0xFF
            nH = (cols_each >> 8) & 0xFF
            d += ESC + b'*\x00' + bytes([nL, nH]) + bytes([0xFF] * cols_each)
        d += color(0) + CR + LF
    return d


# ---------------------------------------------------------------------------
# Main page assembly
# ---------------------------------------------------------------------------
def build():
    d = b''
    now = datetime.datetime.now().strftime('%Y-%m-%d %H:%M')

    d += init() + nlq() + pica() + ls_sixth()

    # ── TITLE ────────────────────────────────────────────────────────────────
    d += blank()
    d += wide_on() + bold_on()
    d += centre('PRINTER TEST PAGE', 39)   # 39 wide cols = 78 normal cols
    d += wide_off() + bold_off()
    d += blank()
    d += bold_on()
    d += centre('Fedora Linux  -  CUPS / ESC-P  (9-pin)', 78)
    d += bold_off()
    d += centre(f'Generated: {now}', 78)
    d += blank()
    d += rule(b'=')

    # ── GRAPHICS ROW ─────────────────────────────────────────────────────────
    # Line spacing = 24/216" = exactly 8/72" so bit-image rows tile with no gaps
    d += ls_n216(24)
    d += blank()

    lw, lfn = panel_freq_sweep(96)
    gw, gfn = panel_grey(90)
    cw, cfn = panel_checker(72)
    gap = 6

    # Column header labels
    freq_label  = 'Stripe sweep'
    grey_label  = 'Greyscale (0%-100%)'
    check_label = 'Checkerboard'

    d += (bold_on()
          + SI
          + b'  ' + freq_label.encode().ljust(lw // 6)
          + b' ' * gap
          + grey_label.encode().ljust(gw // 6)
          + b' ' * gap
          + check_label.encode()
          + DC2
          + bold_off()
          + CR + LF)

    for _ in range(8):
        row = (bytes(lfn(c) for c in range(lw))
               + bytes(gap)
               + bytes(gfn(c) for c in range(gw))
               + bytes(gap)
               + bytes(cfn(c) for c in range(cw)))
        ncols = len(row)
        nL, nH = ncols & 0xFF, (ncols >> 8) & 0xFF
        d += ESC + b'*\x00' + bytes([nL, nH]) + row + CR + LF

    d += ls_sixth()
    d += blank()

    # ── COLOUR SWATCH ────────────────────────────────────────────────────────
    d += rule(b'-')
    d += (bold_on() + b'Colour Test  ' + bold_off()
          + b'(colour printer: distinct bands; mono: all black)' + CR + LF)
    d += blank()
    d += ls_n216(24)
    d = colour_row(d, bands=4, cols_each=10)
    d += ls_sixth()
    # Labels in condensed
    d += SI + b'  '
    for name in COLOUR_NAMES:
        d += name[:10].ljust(10).encode()
    d += DC2 + CR + LF
    d += blank()

    # ── TEXT QUALITY & STYLE MATRIX ──────────────────────────────────────────
    d += rule(b'-')
    d += bold_on() + b'Text Quality & Style Tests' + bold_off() + CR + LF
    d += blank()

    SAMPLE = 'The quick brown fox jumps over the lazy dog. 0123456789'

    rows = [
        (b'10 CPI normal   : ', pica(),        b'',           b'',           SAMPLE[:50]),
        (b'10 CPI bold     : ', pica(),         bold_on(),     bold_off(),    SAMPLE[:50]),
        (b'10 CPI italic   : ', pica(),         italic_on(),   italic_off(),  SAMPLE[:50]),
        (b'10 CPI underline: ', pica(),         uline_on(),    uline_off(),   SAMPLE[:46]),
        (b'10 CPI dbl-strk : ', pica(),         dstrike_on(),  dstrike_off(), SAMPLE[:46]),
        (b'12 CPI (elite)  : ', elite(),        b'',           b'',           SAMPLE[:55]),
        (b'15 CPI (micro)  : ', micro(),        b'',           b'',           SAMPLE[:60]),
        (b'Condensed ~17cpi: ', pica() + SI,    b'',           DC2,           SAMPLE[:65]),
    ]

    for label, setup, attr_on, attr_off, sample in rows:
        d += label + setup + attr_on + sample.encode() + attr_off + pica() + CR + LF

    d += blank()
    d += (b'Double-width    : '
          + wide_on() + bold_on()
          + b'THE QUICK BROWN FOX'
          + bold_off() + wide_off() + CR + LF)
    d += blank()
    d += draft() + b'Draft NLQ off : ' + SAMPLE[:55].encode() + CR + LF
    d += nlq()   + b'Draft NLQ on  : ' + SAMPLE[:55].encode() + CR + LF
    d += blank()

    # ── PATTERN / GRAPHICS TESTS ─────────────────────────────────────────────
    d += rule(b'-')
    d += (bold_on() + b'Graphics Tests  ' + bold_off()
          + b'(single-density mode 0, 60 dpi H x 8-pin V)' + CR + LF)
    d += blank()
    d += ls_n216(24)

    patterns = [
        ('Full density   100%', lambda c: 0xFF),
        ('Half density    75%', lambda c: 0xEE),
        ('Half density    50%', lambda c: 0xAA),
        ('Quarter density 25%', lambda c: 0x22),
        ('Vert stripes  2px  ', lambda c: 0xFF if c % 2 == 0 else 0x00),
        ('Vert stripes  4px  ', lambda c: 0xFF if (c // 4) % 2 == 0 else 0x00),
        ('Top half only      ', lambda c: 0xF0),
        ('Diagonal           ', lambda c: 1 << (c % 8)),
    ]

    for label, fn in patterns:
        d += SI + label[:20].encode() + b': ' + DC2
        d += bitrow(240, fn)

    d += ls_sixth()
    d += blank()

    # ── ALIGNMENT & RULER ────────────────────────────────────────────────────
    d += rule(b'-')
    d += bold_on() + b'Alignment & Margins  ' + bold_off()
    d += b'(80-column, 10 CPI)' + CR + LF
    d += blank()

    tens = ''.join(str((i + 1) // 10) if (i + 1) % 10 == 0 else ' ' for i in range(78))
    ones = ''.join(str((i + 1) % 10) for i in range(78))
    d += tens.encode() + CR + LF
    d += ones.encode() + CR + LF
    d += b'|' + b'-' * 76 + b'|' + CR + LF

    for i in range(1, 7):
        inner = f'<margin  line {i:2d}  {"." * 44}  margin>'
        d += b'|' + inner[:76].encode() + b'|' + CR + LF

    d += b'|' + b'-' * 76 + b'|' + CR + LF
    d += blank()

    # ── FOOTER ───────────────────────────────────────────────────────────────
    d += rule(b'=')
    d += bold_on()
    d += centre('If all sections printed correctly, the printer is working.', 78)
    d += bold_off()
    d += centre('aun-filestore ESC/P test page  (Fedora / CUPS)', 78)
    d += blank()

    d += FF
    return d


if __name__ == '__main__':
    out  = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'testpage.prn')
    data = build()
    with open(out, 'wb') as f:
        f.write(data)
    print(f'Written {len(data)} bytes to {out}')

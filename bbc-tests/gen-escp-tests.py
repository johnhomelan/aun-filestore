#!/usr/bin/env python3
"""
Generate ESC/P test files for PostScript converter testing.
Run with: python3 gen-escp-tests.py
Writes .prn files to the current directory.
"""

import os
import struct

ESC = b'\x1b'
CR  = b'\r'
LF  = b'\n'
FF  = b'\x0c'
SI  = b'\x0f'   # condensed on
DC2 = b'\x12'   # condensed off (pica)
HT  = b'\x09'   # horizontal tab

# --- command builders ---

def init():           return ESC + b'@'
def bold_on():        return ESC + b'E'
def bold_off():       return ESC + b'F'
def italic_on():      return ESC + b'4'
def italic_off():     return ESC + b'5'
def uline_on():       return ESC + b'-\x01'
def uline_off():      return ESC + b'-\x00'
def dstrike_on():     return ESC + b'G'
def dstrike_off():    return ESC + b'H'
def condensed_on():   return SI
def condensed_off():  return DC2
def wide_on():        return ESC + b'W\x01'
def wide_off():       return ESC + b'W\x00'
def pica():           return ESC + b'P'    # 10 CPI
def elite():          return ESC + b'M'    # 12 CPI
def micro():          return ESC + b'g'    # 15 CPI
def ls_sixth():       return ESC + b'2'    # 1/6" line spacing
def ls_eighth():      return ESC + b'0'    # 1/8" line spacing
def ls_n216(n):       return ESC + b'3' + bytes([n])   # n/216"
def ls_n60(n):        return ESC + b'A' + bytes([n])   # n/60"
def draft():          return ESC + b'x\x00'
def nlq():            return ESC + b'x\x01'
def font(n):          return ESC + b'k' + bytes([n])   # 0=Roman 1=SansSerif 2=Courier
def lmargin(n):       return ESC + b'l' + bytes([n])
def rmargin(n):       return ESC + b'Q' + bytes([n])
def set_tabs(*cols):  return ESC + b'D' + bytes(cols) + b'\x00'
def color(n):         return ESC + b'r' + bytes([n])   # 0=blk 1=mag 2=cyn 4=yel 5=red 6=grn

def crlf():           return CR + LF
def line(text):       return text.encode() + CR + LF


def bit_image(mode, columns, data):
    """Single-pass bit image. data must be len columns * (3 if 24-pin mode else 1)."""
    nL = columns & 0xFF
    nH = (columns >> 8) & 0xFF
    return ESC + b'*' + bytes([mode, nL, nH]) + bytes(data)


def write(name, data):
    path = os.path.join(os.path.dirname(__file__), name)
    with open(path, 'wb') as f:
        f.write(data)
    print(f"  {name}: {len(data)} bytes")


# -----------------------------------------------------------------------
# TEST 1: plain text — what PSTEST.BBC actually sends
# -----------------------------------------------------------------------
def test_plain():
    d = b''
    d += init()
    d += line('================================================')
    d += line('ECONET PRINT SERVER TEST JOB')
    d += line('================================================')
    d += crlf()
    d += line('HomeLan aun-filestore print service test')
    d += line('If you can read this the print server works!')
    d += crlf()
    d += line('================================================')
    d += FF
    write('01-plain.prn', d)


# -----------------------------------------------------------------------
# TEST 2: bold and italic
# -----------------------------------------------------------------------
def test_bold_italic():
    d = b''
    d += init()
    d += line('Normal text line.')
    d += bold_on()
    d += line('This line is bold.')
    d += bold_off()
    d += italic_on()
    d += line('This line is italic.')
    d += italic_off()
    d += bold_on() + italic_on()
    d += line('This line is bold AND italic.')
    d += bold_off() + italic_off()
    d += line('Back to normal.')
    d += FF
    write('02-bold-italic.prn', d)


# -----------------------------------------------------------------------
# TEST 3: underline and double-strike
# -----------------------------------------------------------------------
def test_underline():
    d = b''
    d += init()
    d += line('Normal line.')
    d += uline_on()
    d += line('This line is underlined.')
    d += uline_off()
    d += dstrike_on()
    d += line('This line is double-strike.')
    d += dstrike_off()
    d += uline_on() + dstrike_on()
    d += line('Underline and double-strike combined.')
    d += uline_off() + dstrike_off()
    d += bold_on() + uline_on()
    d += line('Bold underlined.')
    d += bold_off() + uline_off()
    d += line('Normal again.')
    d += FF
    write('03-underline.prn', d)


# -----------------------------------------------------------------------
# TEST 4: character pitch (10 / 12 / 15 CPI and condensed)
# -----------------------------------------------------------------------
def test_pitch():
    ruler = '|' + ''.join(str(i % 10) for i in range(1, 80)) + '|'
    d = b''
    d += init()
    d += pica()
    d += line('10 CPI (pica):')
    d += line(ruler[:40])
    d += crlf()
    d += elite()
    d += line('12 CPI (elite):')
    d += line(ruler[:48])
    d += crlf()
    d += micro()
    d += line('15 CPI (micro):')
    d += line(ruler[:60])
    d += crlf()
    d += pica() + condensed_on()
    d += line('Condensed (approx 17 CPI):')
    d += line(ruler[:68])
    d += condensed_off()
    d += crlf()
    d += pica() + wide_on()
    d += line('Double-width (5 CPI):')
    d += line(ruler[:20])
    d += wide_off()
    d += crlf()
    d += pica() + condensed_on() + wide_on()
    d += line('Condensed double-width:')
    d += line(ruler[:34])
    d += condensed_off() + wide_off()
    d += FF
    write('04-pitch.prn', d)


# -----------------------------------------------------------------------
# TEST 5: line spacing
# -----------------------------------------------------------------------
def test_linespacing():
    d = b''
    d += init()
    d += ls_sixth()
    d += line('1/6" line spacing (default)')
    d += line('Line 2')
    d += line('Line 3')
    d += crlf()
    d += ls_eighth()
    d += line('1/8" line spacing (compressed)')
    d += line('Line 2')
    d += line('Line 3')
    d += crlf()
    d += ls_n216(24)    # 24/216 = 1/9"
    d += line('24/216" line spacing')
    d += line('Line 2')
    d += line('Line 3')
    d += crlf()
    d += ls_n216(54)    # 54/216 = 1/4"
    d += line('54/216" line spacing (expanded)')
    d += line('Line 2')
    d += line('Line 3')
    d += crlf()
    d += ls_n60(12)     # 12/60 = 1/5"
    d += line('12/60" line spacing')
    d += line('Line 2')
    d += line('Line 3')
    d += ls_sixth()
    d += FF
    write('05-linespacing.prn', d)


# -----------------------------------------------------------------------
# TEST 6: tab stops
# -----------------------------------------------------------------------
def test_tabs():
    d = b''
    d += init()
    d += set_tabs(10, 20, 30, 40, 50, 60)
    d += b'Col 0' + HT + b'Col 10' + HT + b'Col 20' + HT + b'Col 30' + CR + LF
    d += b'A' + HT + b'B' + HT + b'C' + HT + b'D' + HT + b'E' + HT + b'F' + CR + LF
    d += crlf()
    # Default tabs (every 8 columns) after reset
    d += init()
    d += b'|' + HT + b'|' + HT + b'|' + HT + b'|' + HT + b'|' + CR + LF
    d += crlf()
    d += line('(each | is one tab stop apart)')
    d += FF
    write('06-tabs.prn', d)


# -----------------------------------------------------------------------
# TEST 7: overprint (CR without LF) — strikethrough style
# -----------------------------------------------------------------------
def test_overprint():
    d = b''
    d += init()
    d += line('Normal line.')
    # Print a line then overprint with hyphens to fake strikethrough
    d += b'This text is overprinted.' + CR
    d += b'-------------------------' + CR + LF
    d += crlf()
    d += line('Back to normal.')
    d += FF
    write('07-overprint.prn', d)


# -----------------------------------------------------------------------
# TEST 8: margins
# -----------------------------------------------------------------------
def test_margins():
    ruler = ''.join(str(i % 10) for i in range(80))
    d = b''
    d += init()
    d += line('No margins set:')
    d += line(ruler)
    d += crlf()
    d += lmargin(5)
    d += line('Left margin at column 5:')
    d += line(ruler[:70])
    d += crlf()
    d += lmargin(10) + rmargin(50)
    d += line('Left=10, Right=50:')
    d += line(ruler[:40])
    d += crlf()
    d += lmargin(0) + rmargin(80)
    d += line('Margins cleared.')
    d += FF
    write('08-margins.prn', d)


# -----------------------------------------------------------------------
# TEST 9: font selection (typeface)
# -----------------------------------------------------------------------
def test_fonts():
    d = b''
    d += init()
    for n, name in [(0, 'Roman'), (1, 'Sans Serif'), (2, 'Courier'),
                    (3, 'Prestige'), (4, 'Script'), (5, 'OCR-B')]:
        d += font(n)
        d += line(f'Font {n}: {name} -- The quick brown fox jumps over the lazy dog.')
    d += init()
    d += FF
    write('09-fonts.prn', d)


# -----------------------------------------------------------------------
# TEST 10: draft vs NLQ quality
# -----------------------------------------------------------------------
def test_quality():
    d = b''
    d += init()
    d += draft()
    d += line('Draft mode: The quick brown fox jumps over the lazy dog.')
    d += nlq()
    d += line('NLQ mode:   The quick brown fox jumps over the lazy dog.')
    d += draft()
    d += FF
    write('10-quality.prn', d)


# -----------------------------------------------------------------------
# TEST 11: bit image graphics — 9-pin single density (mode 0, 60 dpi)
#   Each byte = 8 vertical dots, MSB at top.
#   We draw: a solid horizontal bar, a diagonal, and a checkerboard.
# -----------------------------------------------------------------------
def test_graphics():
    d = b''
    d += init()
    d += ls_n216(24)    # set line spacing to match 8-pin image height (8/72" = 24/216)

    def image_row(pixels_fn, cols=240):
        data = [pixels_fn(col) for col in range(cols)]
        return bit_image(0, cols, data) + CR + LF

    d += line('Solid bar (8px tall, 240 dots wide):')
    d += image_row(lambda c: 0xFF)   # all pins fired

    d += line('Top half bar:')
    d += image_row(lambda c: 0xF0)   # top 4 pins

    d += line('Diagonal stripes:')
    d += image_row(lambda c: (1 << (c % 8)))

    d += line('Checkerboard:')
    d += image_row(lambda c: 0xAA if (c // 8) % 2 == 0 else 0x55)

    d += line('Vertical stripes (every 4px):')
    d += image_row(lambda c: 0xFF if (c // 4) % 2 == 0 else 0x00)

    d += ls_sixth()
    d += FF
    write('11-graphics.prn', d)


# -----------------------------------------------------------------------
# TEST 12: ESC @ reset mid-document
# -----------------------------------------------------------------------
def test_reset():
    d = b''
    d += init()
    d += bold_on() + italic_on() + uline_on() + condensed_on()
    d += line('Bold+italic+underline+condensed before reset.')
    d += init()    # reset should clear all attributes
    d += line('After ESC @ reset: should be plain pica.')
    d += FF
    write('12-reset.prn', d)


# -----------------------------------------------------------------------
# TEST 13: multipage document with form feeds
# -----------------------------------------------------------------------
def test_multipage():
    d = b''
    for page in range(1, 4):
        d += init()
        d += line(f'PAGE {page} OF 3')
        d += line('-' * 24)
        d += crlf()
        for row in range(1, 6):
            d += line(f'  Line {row} of page {page}.')
        d += crlf()
        d += line('--- end of page ---')
        d += FF
    write('13-multipage.prn', d)


# -----------------------------------------------------------------------
# TEST 14: realistic "letter" document — mixed formatting
# -----------------------------------------------------------------------
def test_letter():
    d = b''
    d += init() + nlq() + pica() + ls_sixth()
    d += crlf() * 3
    # heading
    d += wide_on() + bold_on()
    d += line('  HomeLan Acorn Econet Test')
    d += wide_off() + bold_off()
    d += crlf()
    # address block
    d += line('From: Test Harness v1.0')
    d += line('  aun-filestore project')
    d += crlf()
    # body
    d += line('Dear Tester,')
    d += crlf()
    d += line('This document exercises mixed ESC/P formatting:')
    d += crlf()
    d += b'  ' + bold_on() + b'Bold text' + bold_off() + b', ' \
       + italic_on() + b'italic text' + italic_off() + b', and ' \
       + uline_on() + b'underlined text' + uline_off() + b' in one line.' + CR + LF
    d += crlf()
    d += elite()
    d += line('  This paragraph uses 12 CPI (elite pitch). The quick brown fox')
    d += line('  jumps over the lazy dog. Pack my box with five dozen liquor jugs.')
    d += crlf()
    d += pica() + condensed_on()
    d += line('Condensed text for fine print: see terms and conditions overleaf.')
    d += condensed_off()
    d += crlf()
    d += ls_n216(54)    # wide line spacing for signature area
    d += line('Yours sincerely,')
    d += line('')
    d += line('The Test Generator')
    d += ls_sixth()
    d += FF
    write('14-letter.prn', d)


# -----------------------------------------------------------------------
# TEST 15: edge cases
#   - empty page (just FF)
#   - trailing data after last FF
#   - LF-only line endings (no CR)
#   - very long line
# -----------------------------------------------------------------------
def test_edge():
    d = b''
    d += init()
    # empty first page
    d += FF
    # LF-only line endings
    d += b'Line ending with LF only' + LF
    d += b'Another LF-only line' + LF
    d += crlf()
    # CR only (no LF) — should stay on same line
    d += b'Should be' + CR + b'overprinted' + CR + LF
    d += crlf()
    # very long line (> 80 chars, should wrap or truncate)
    d += line('X' * 160)
    # trailing text with no final FF
    d += line('Trailing text after last page (no trailing FF).')
    write('15-edge.prn', d)


# -----------------------------------------------------------------------
if __name__ == '__main__':
    print('Generating ESC/P test files...')
    test_plain()
    test_bold_italic()
    test_underline()
    test_pitch()
    test_linespacing()
    test_tabs()
    test_overprint()
    test_margins()
    test_fonts()
    test_quality()
    test_graphics()
    test_reset()
    test_multipage()
    test_letter()
    test_edge()
    print('Done.')

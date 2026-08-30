/**
 * Teletext page renderer for the admin "Browse Pages" viewer.
 *
 * Self-initialising: on load, finds every `<canvas data-teletext-src="...">`
 * on the page, fetches the URL as a raw 1024-byte Mode 7 screen dump (see
 * docs/protocols/teletext.md), decodes it, and draws it. No dependencies,
 * no build step — this file is served verbatim by the admin webserver
 * (TeletextController::renderScript()), the same way favicon.ico is.
 *
 * Page numbers (exactly 3 consecutive decimal digits, e.g. a "see page 130"
 * reference in running text) are clickable: hovering shows a pointer cursor,
 * clicking navigates to that page on the current channel.
 *
 * Decodes Teletext Level 1 (SAA5050) control codes and the English/UK G0
 * character set, per the verified references:
 *  - Control code table (0x00-0x1F, this project's raw on-disk form —
 *    equivalently 0x80-0x9F in the transmitted/bit-7-set form documented
 *    at https://mdfs.net/Info/Comp/Teletext/Controls, which this project's
 *    own TeefaxTtiParser already normalises down to 0x00-0x1F on import).
 *  - English/UK G0 alpha substitutions vs ASCII, per Wikipedia's
 *    "Teletext character set" article.
 *  - G1 mosaic graphics: valid codes are 0x20-0x3F and 0x60-0x7F only
 *    (0x40-0x5F still show their alpha glyph even in graphics mode - a
 *    real SAA5050 quirk); the 6 sub-cells map to bits 0,1,2,3,4,6 of the
 *    byte (bit 5 is always set and carries no sub-cell information) in
 *    reading order: top-left, top-right, middle-left, middle-right,
 *    bottom-left, bottom-right.
 */
(function () {
	'use strict';

	var ROWS = 25;
	var COLS = 40;
	var CELL_W = 12;
	var CELL_H = 20;

	var PALETTE = ['#000000', '#ff0000', '#00ff00', '#ffff00', '#0000ff', '#ff00ff', '#00ffff', '#ffffff'];

	// English/UK G0 national option substitutions vs ASCII at these code points
	var G0_SUBSTITUTIONS = {
		0x23: '£', // £
		0x5B: '←', // ←
		0x5C: '½', // ½
		0x5D: '→', // →
		0x5E: '↑', // ↑
		0x5F: '#',
		0x60: '−', // − (dash)
		0x7B: '¼', // ¼
		0x7C: '‖', // ‖
		0x7D: '¾', // ¾
		0x7E: '÷'  // ÷
	};

	function g0Char(code) {
		if (Object.prototype.hasOwnProperty.call(G0_SUBSTITUTIONS, code)) {
			return G0_SUBSTITUTIONS[code];
		}
		return String.fromCharCode(code);
	}

	// The 6 mosaic sub-cell bits, in reading order (top-left, top-right, middle-left,
	// middle-right, bottom-left, bottom-right). Bit 5 is never used.
	var MOSAIC_BITS = [0, 1, 2, 3, 4, 6];

	function isMosaicCode(code) {
		return (code >= 0x20 && code <= 0x3F) || (code >= 0x60 && code <= 0x7F);
	}

	/**
	 * Decodes the raw 1000 body bytes into a 25x40 grid of cells, each
	 * {ch, fg, bg, graphics, separated, doubleHeight, doubleWidth, conceal, reveal}
	 */
	function decodeRows(bytes) {
		var rows = [];
		for (var r = 0; r < ROWS; r++) {
			var fg = 7, bg = 0;
			var graphics = false, separated = false, holdGraphics = false;
			var doubleHeight = false, doubleWidth = false;
			var conceal = false;
			var heldChar = 0x20;
			var row = [];

			for (var c = 0; c < COLS; c++) {
				var code = bytes[r * COLS + c] & 0x7F;

				if (code < 0x20) {
					// Control code: apply its effect first, then render this cell
					// using the (now current) state - a blank, or the held graphics
					// character if hold-graphics is active in graphics mode.
					switch (code) {
						case 0x00: case 0x01: case 0x02: case 0x03:
						case 0x04: case 0x05: case 0x06: case 0x07:
							fg = code; graphics = false; conceal = false;
							break;
						case 0x08: /* flash on - static viewer, no animation */ break;
						case 0x09: /* steady */ break;
						case 0x0A: /* end box */ break;
						case 0x0B: /* start box */ break;
						case 0x0C: doubleHeight = false; doubleWidth = false; break;
						case 0x0D: doubleHeight = true; break;
						case 0x0E: doubleWidth = true; break;
						case 0x0F: doubleHeight = true; doubleWidth = true; break;
						case 0x10: case 0x11: case 0x12: case 0x13:
						case 0x14: case 0x15: case 0x16: case 0x17:
							fg = code - 0x10; graphics = true; conceal = false;
							break;
						case 0x18: conceal = true; break;
						case 0x19: separated = false; break;
						case 0x1A: separated = true; break;
						case 0x1B: /* toggle G0 set - only one supported here */ break;
						case 0x1C: bg = 0; break;
						case 0x1D: bg = fg; break;
						case 0x1E: holdGraphics = true; break;
						case 0x1F: holdGraphics = false; break;
					}

					if (graphics && holdGraphics) {
						row.push({ ch: heldChar, fg: fg, bg: bg, graphics: true, separated: separated, doubleHeight: doubleHeight, doubleWidth: doubleWidth, conceal: conceal });
					} else {
						row.push({ ch: 0x20, fg: fg, bg: bg, graphics: false, separated: separated, doubleHeight: doubleHeight, doubleWidth: doubleWidth, conceal: false });
					}
					continue;
				}

				if (graphics && isMosaicCode(code)) {
					heldChar = code;
					row.push({ ch: code, fg: fg, bg: bg, graphics: true, separated: separated, doubleHeight: doubleHeight, doubleWidth: doubleWidth, conceal: conceal });
				} else {
					// Alpha character - also used for 0x40-0x5F even in graphics mode
					row.push({ ch: code, fg: fg, bg: bg, graphics: false, separated: separated, doubleHeight: doubleHeight, doubleWidth: doubleWidth, conceal: conceal });
				}
			}
			rows.push(row);
		}
		return rows;
	}

	/** Decodes the BCD subpage number from the trailing bytes at 0x3FE/0x3FF */
	function decodeSubpage(bytes) {
		if (bytes.length < 1024) {
			return null;
		}
		var hi = bytes[0x3FE], lo = bytes[0x3FF];
		var bcd = function (b) { return ((b >> 4) & 0x0F) * 10 + (b & 0x0F); };
		return bcd(hi) * 100 + bcd(lo);
	}

	/**
	 * Finds every maximal run of exactly 3 consecutive decimal-digit alpha
	 * characters (a run of 1, 2, or 4+ digits is not a page number and is
	 * skipped) and returns {row, startCol, endCol, page} for each - the
	 * clickable page-number links.
	 */
	function findPageLinks(rows) {
		var links = [];
		for (var r = 0; r < ROWS; r++) {
			var c = 0;
			while (c < COLS) {
				var cell = rows[r][c];
				if (cell.graphics || cell.ch < 0x30 || cell.ch > 0x39) {
					c++;
					continue;
				}
				var start = c;
				var digits = '';
				while (c < COLS && !rows[r][c].graphics && rows[r][c].ch >= 0x30 && rows[r][c].ch <= 0x39) {
					digits += String.fromCharCode(rows[r][c].ch);
					c++;
				}
				if (digits.length === 3) {
					links.push({ row: r, startCol: start, endCol: c - 1, page: digits });
				}
			}
		}
		return links;
	}

	/** Maps a mouse event's client coordinates to a {col, row} cell, accounting for CSS scaling */
	function cellFromEvent(canvas, evt) {
		var rect = canvas.getBoundingClientRect();
		var scaleX = canvas.width / rect.width;
		var scaleY = canvas.height / rect.height;
		return {
			col: Math.floor((evt.clientX - rect.left) * scaleX / CELL_W),
			row: Math.floor((evt.clientY - rect.top) * scaleY / CELL_H)
		};
	}

	function findLinkAt(canvas, col, row) {
		var links = canvas._teletextLinks || [];
		for (var i = 0; i < links.length; i++) {
			if (links[i].row === row && col >= links[i].startCol && col <= links[i].endCol) {
				return links[i];
			}
		}
		return null;
	}

	/** Extracts the "channel" query param from a page-data URL (same channel a clicked page number loads on) */
	function extractChannel(src) {
		try {
			return new URL(src, window.location.href).searchParams.get('channel');
		} catch (e) {
			return null;
		}
	}

	function drawMosaic(ctx, x, y, w, h, code, separated) {
		var cellW = w / 2;
		// h isn't always a multiple of 3 (e.g. CELL_H=20), so a plain h/3 sub-cell
		// height leaves fractional pixel boundaries between the 3 sub-cell rows -
		// the canvas then anti-aliases each fillRect's edge independently, leaving
		// a faint seam between blocks that are meant to touch exactly (invisible on
		// a real TV, very visible here). Rounding each row boundary to a whole
		// pixel makes adjacent sub-cells (and adjacent rows of graphics characters,
		// which share the same y = r*CELL_H integer boundary) abut exactly.
		var rowBounds = [0, Math.round(h / 3), Math.round(h * 2 / 3), h];
		for (var i = 0; i < 6; i++) {
			var bit = MOSAIC_BITS[i];
			if ((code & (1 << bit)) === 0) {
				continue;
			}
			var col = i % 2, rowIdx = Math.floor(i / 2);
			var subY = rowBounds[rowIdx];
			var subH = rowBounds[rowIdx + 1] - subY;
			var pad = separated ? 1 : 0;
			ctx.fillRect(x + col * cellW + pad, y + subY + pad, cellW - pad * 2, subH - pad * 2);
		}
	}

	function render(canvas, bytes, revealConcealed) {
		canvas.width = COLS * CELL_W;
		canvas.height = ROWS * CELL_H;
		var ctx = canvas.getContext('2d');
		ctx.textBaseline = 'top';
		ctx.font = (CELL_H - 4) + 'px monospace';

		var rows = decodeRows(bytes);
		canvas._teletextLinks = findPageLinks(rows);

		ctx.fillStyle = '#000000';
		ctx.fillRect(0, 0, canvas.width, canvas.height);

		// Double height is a whole-ROW flag on real hardware, not a per-column one:
		// the receiver decides how many physical scanlines a row occupies before it
		// starts drawing it, so if ANY column requests double height, the entire row
		// occupies two scanline-rows and the row below is entirely repurposed as its
		// bottom half - even for columns that were themselves single-height above
		// (they show only their own background colour down there, no glyph; per the
		// SAA5050 behaviour "the top row can mix single and double height
		// characters" but the row below can never be independently single-height).
		var rowIsDoubleHeight = [];
		for (var rh = 0; rh < ROWS; rh++) {
			var flagged = false;
			for (var ch = 0; ch < COLS; ch++) {
				if (rows[rh][ch].doubleHeight) {
					flagged = true;
					break;
				}
			}
			rowIsDoubleHeight.push(flagged);
		}

		var suppressed = [];
		for (var i = 0; i < ROWS; i++) {
			suppressed.push(new Array(COLS).fill(false));
		}
		for (var sr = 0; sr < ROWS - 1; sr++) {
			if (rowIsDoubleHeight[sr]) {
				for (var sc = 0; sc < COLS; sc++) {
					suppressed[sr + 1][sc] = true;
				}
			}
		}

		for (var r = 0; r < ROWS; r++) {
			var yBg = r * CELL_H;
			// The background fill follows the whole-row height, not the individual
			// cell's own doubleHeight flag, so single-height columns within a
			// double-height-flagged row still correctly extend their background
			// colour down into the row below instead of leaving it black there.
			var bgRowH = rowIsDoubleHeight[r] ? CELL_H * 2 : CELL_H;
			for (var c = 0; c < COLS; c++) {
				if (suppressed[r][c]) {
					continue;
				}
				var bgCell = rows[r][c];
				var bgW = bgCell.doubleWidth ? CELL_W * 2 : CELL_W;
				ctx.fillStyle = PALETTE[bgCell.bg];
				ctx.fillRect(c * CELL_W, yBg, bgW, bgRowH);
			}
		}

		for (var r2 = 0; r2 < ROWS; r2++) {
			var y = r2 * CELL_H;
			for (var c2 = 0; c2 < COLS; c2++) {
				if (suppressed[r2][c2]) {
					continue;
				}
				var cell = rows[r2][c2];
				if (cell.conceal && !revealConcealed) {
					continue;
				}
				if (cell.ch === 0x20) {
					continue;
				}

				var h = cell.doubleHeight ? CELL_H * 2 : CELL_H;
				var w = cell.doubleWidth ? CELL_W * 2 : CELL_W;
				var x = c2 * CELL_W;

				ctx.fillStyle = PALETTE[cell.fg];
				if (cell.graphics) {
					drawMosaic(ctx, x, y, w, h, cell.ch, cell.separated);
				} else {
					ctx.save();
					ctx.scale(cell.doubleWidth ? 2 : 1, cell.doubleHeight ? 2 : 1);
					ctx.fillText(g0Char(cell.ch), x / (cell.doubleWidth ? 2 : 1), y / (cell.doubleHeight ? 2 : 1));
					ctx.restore();
				}
			}
		}

		return decodeSubpage(bytes);
	}

	function renderError(canvas, message) {
		canvas.width = COLS * CELL_W;
		canvas.height = ROWS * CELL_H;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = '#000000';
		ctx.fillRect(0, 0, canvas.width, canvas.height);
		ctx.fillStyle = '#ff0000';
		ctx.font = '14px monospace';
		ctx.fillText(message, 10, 20);
	}

	function findLabel(canvas) {
		var id = canvas.getAttribute('data-teletext-label');
		return id ? document.getElementById(id) : null;
	}

	function attachLinkHandlers(canvas) {
		canvas.addEventListener('mousemove', function (evt) {
			var cell = cellFromEvent(canvas, evt);
			canvas.style.cursor = findLinkAt(canvas, cell.col, cell.row) ? 'pointer' : 'default';
		});
		canvas.addEventListener('click', function (evt) {
			var cell = cellFromEvent(canvas, evt);
			var link = findLinkAt(canvas, cell.col, cell.row);
			if (!link) {
				return;
			}
			var channel = extractChannel(canvas.getAttribute('data-teletext-src'));
			if (channel === null) {
				return;
			}
			window.location.href = '/service/teletext/browse?channel=' + encodeURIComponent(channel) + '&page=' + encodeURIComponent(link.page);
		});
	}

	function init() {
		var canvases = document.querySelectorAll('canvas[data-teletext-src]');
		canvases.forEach(function (canvas) {
			attachLinkHandlers(canvas);
			var url = canvas.getAttribute('data-teletext-src');
			fetch(url).then(function (resp) {
				if (!resp.ok) {
					throw new Error('HTTP ' + resp.status);
				}
				return resp.arrayBuffer();
			}).then(function (buf) {
				var bytes = new Uint8Array(buf);
				var subpage = render(canvas, bytes, true);
				var label = findLabel(canvas);
				if (label && subpage !== null) {
					label.textContent = 'Subpage ' + subpage + ' (from page trailer)';
				}
			}).catch(function (err) {
				renderError(canvas, 'Failed to load page: ' + err.message);
			});
		});
	}

	// Exposed so a page that changes what a canvas should show after load can
	// redraw it, instead of reimplementing the decoder. init() only runs once and
	// only fetches each canvas's data-teletext-src as it was at that moment,
	// which is all the "Browse Pages" viewer needs but not enough for the
	// teletext-font-editor, whose preview bytes change with every edit.
	window.teletextRender = render;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

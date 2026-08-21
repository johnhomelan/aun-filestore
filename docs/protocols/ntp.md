# NTP

`ntpd` answers NTP client requests (RFC 5905) using the host system clock as its time source. It
is a resolver of last resort for Econet clients with an IP stack, not a stratum-1 reference
server — see `docs/NTPD.md` for why it's a separate daemon and how it receives its traffic
(always over the Remote Socket Protocol relay, never a bound UDP socket — see
`docs/protocols/remote-socket.md`).

## What's implemented

- The 48-byte NTP base header: client (mode 3) requests are answered with a server (mode 4)
  reply — see [Wire format](#wire-format) below
- The reply's `VN` (version) and `Poll` fields echo the request's own — a conventional courtesy
  most clients rely on, rather than this server imposing a fixed version/poll on every client
- `Stratum` and `Reference ID` are both configurable (`ntp_stratum`, `ntp_reference_id`); default
  to `1` and `LOCL`, the conventional pairing for a self-referencing clock with no upstream time
  source of its own — see [Time source and stratum](#time-source-and-stratum) below
- `Precision` is a fixed `-6` (≈15ms) — see `Ntp\Handler::PRECISION`'s own docblock for why this
  isn't claimed any tighter
- `Root Delay` and `Root Dispersion` are always `0`
- `Origin Timestamp` in the reply is the client's own `Transmit Timestamp`, copied back verbatim
  — this is what lets an NTP client compute round-trip delay and offset; `Receive Timestamp` and
  `Transmit Timestamp` in the reply are both read from the host system clock, at request-receipt
  and reply-send respectively (in practice these are read in immediate succession, since there is
  no work done between them worth timing separately); `Reference Timestamp` is also read from the
  host system clock, at receipt, since a self-referencing clock is always "just synchronised" to
  itself

## What's not implemented

- **Any other mode.** A request that isn't mode 3 (client) — including mode 4 itself, which
  would mean either a misbehaving client or a reflected reply — is silently ignored, matching
  ordinary NTP server behaviour for a mode it has no reply defined for.
- **NTP extension fields, the optional Key ID / Message Digest (MAC) trailer, and NTPv4-specific
  fields beyond the base header.** Only the 48-byte base header is read or written.
- **Recursion or forwarding to an upstream NTP server.** Unlike `dnsd`'s optional DNS forwarder,
  `ntpd` only ever answers from the host system clock — there is no equivalent
  `ntp_forwarder_*` configuration. If the host's own clock is itself kept accurate by a real NTP
  client running on the host (outside this project's scope), that accuracy is what `ntpd` passes
  on; `ntpd` does not query anything upstream itself per-request.
- **Symmetric active/passive peering, broadcast mode, or the control/private modes** (modes 1, 2,
  5, 6, 7) — `ntpd` is a request/reply server only.
- **NTP authentication (symmetric-key or autokey).** Requests and replies are unauthenticated,
  same as plain SNTP.

## Time source and stratum

`ntpd` has no reference clock and doesn't query an upstream server — it reads the host system
clock (via PHP's `microtime(true)`) and reports it, as configured. The default `ntp_stratum = 1`
with `ntp_reference_id = LOCL` is the conventional way a small or embedded NTP server presents a
clock it has no way to independently verify: rather than under-claim accuracy at a high stratum
number (which some clients treat with more suspicion) or falsely claim a specific external
reference, `LOCL` is the standard RFC 5905 convention for "this stratum's own local clock is the
reference." If the host machine's clock is itself disciplined by a real NTP client, `ntpd`
inherits that accuracy for free; if it isn't, `ntpd` has no way to know or warn about that, and
neither does a client relying on it — the same trust assumption as pointing a client directly at
any single, unverified time source.

## Wire format

Standard RFC 5905 §7.3 base header, 48 bytes, no extension fields:

```
Offset  Size  Field
------  ----  -----
0       1     LI(2 bits) VN(3 bits) Mode(3 bits)
1       1     Stratum
2       1     Poll (signed, log2 seconds)
3       1     Precision (signed, log2 seconds)
4       4     Root Delay (16.16 fixed point)
8       4     Root Dispersion (16.16 fixed point)
12      4     Reference ID (4 ASCII characters at stratum 1, e.g. "LOCL")
16      8     Reference Timestamp
24      8     Origin Timestamp
32      8     Receive Timestamp
40      8     Transmit Timestamp
```

A Timestamp field is a 64-bit fixed-point value: a 32-bit unsigned count of seconds since the NTP
epoch (1900-01-01, not the Unix epoch), followed by a 32-bit fraction of a second.

In a reply, `Origin Timestamp` (offset 24) is the *request's* `Transmit Timestamp` (its offset
40), copied byte-for-byte — see `Ntp\Handler` and `Ntp\Messages\NtpMessage::encodeResponse()`.

See `Ntp\Messages\NtpMessage` for the exact encode/decode.

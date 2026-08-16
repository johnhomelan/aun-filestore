# AUN Filestore

A modern Econet fileserver for Acorn/BBC Micro kit — speaking Acorn's **AUN** protocol (Econet over UDP/IP) and, via the EconetUSB adapter, real physical Econet. Bring your 8-bit machines onto a network built for 2026 without giving up a single `*command`.

**GitHub:** https://github.com/johnhomelan/aun-filestore

## Why you'd want this

Run one lightweight container and get a full Econet site: file server, print server, IP routing, and a handful of services for vintage clients that most bridges never touch — all managed from a browser.

## Network Encapsulation

Three transports run **simultaneously**, with the correct one chosen automatically per packet — services never know which was used:

| Encapsulation | Transport | Use case |
|---|---|---|
| **AUN** | UDP/IP | Standard Acorn networking over Ethernet |
| **Piconet** | EconetUSB (serial) | Real physical Econet wire |
| **WebSocket** | TCP / WebSocket | Browser BBC emulators (e.g. jsbeeb) |
| **Remote Bridge** | TCP | Linking two server instances across a network |

### Remote Bridge

Connect two AUN Filestore instances over TCP so Econet clients on one site transparently reach services on the other — HMAC-authenticated, with the local Piconet interface switching to monitor mode to forward inter-network traffic. Perfect for linking a home Econet to a friend's, or a physical wire to a cloud-hosted network.

## Services

* **File Server** — full Acorn filestore semantics (priv model, boot flags, quotas, `*command` compatibility, MDFS extras)
* **Print Server** — spools Econet print jobs per-user, with optional conversion via a configurable shell script, downloadable from the admin UI
* **IPv4 over Econet** — DCI-2/DCI-4 ARP, IP routing between Econet networks, a TCP NAT proxy so BBC Micros can reach real LAN/internet TCP services, and basic ICMP
* **BBCTerm** — terminal access to Linux shell commands from a BBC client
* **TorchNet** — CP/M file service for Torch Communicator workstations
* **MaceMail** — the vintage 1985 MaceMail electronic-mail system, backed by your filestore users
* **Teletext** — an Econet TSERV teletext server, optionally auto-populated from the Teefax archive
* **Viewdata** — bridges an Econet station to a remote Prestel-style viewdata server (e.g. Telstar) over TCP

## Storage (VFS)

Pluggable, layered virtual filesystem — mix and match per subtree:

* Native Unix filesystem
* Acorn DFS/ADFS/AFS and SJ Research MDFS/HDFS disc images, mounted as directories
* Amazon S3 (or S3-compatible, e.g. MinIO) buckets
* Remote HTTP catalogues, for shared read-only public archives

## Admin Web Front End

A browser-based admin UI starts automatically (`:8080` by default) — live service status, sessions and open streams, ARP/routing/NAT tables, print spool downloads, and more.

## Docker Usage

```
docker run --name=filestored -p 32768/udp -d crowly/aun-filestore
```

UDP port `32768` must be exposed — that's the AUN traffic port.

### Persistent storage

```
docker run --name=filestored -p 32768/udp \
  -v /storage/root:/var/lib/aun-filestore-root \
  -v /storage/print:/var/spool/aun-filestore-print \
  -v /storage/config:/etc/aun-filestored \
  -v /storage/log:/var/log \
  -d crowly/aun-filestore
```

| Volume | Purpose |
|---|---|
| `/var/lib/aun-filestore-root` | Root of the exported filestore |
| `/var/spool/aun-filestore-print` | Submitted print jobs |
| `/etc/aun-filestored` | Config directory |
| `/var/log` | Server logs |

Full config reference and protocol docs are in the [GitHub repo](https://github.com/johnhomelan/aun-filestore).

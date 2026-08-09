# Traffic Encapsulation — Developer Guide

This document describes how network traffic arrives at, and is sent from, the
`aun-filestore` server. It explains the architecture in enough detail that a
developer can add a new encapsulation type.

---

## The core idea

All services (file server, print server, IPv4, …) work with a single
network-neutral type: **`EconetPacket`**
(`src/include/classes/Messages/EconetPacket.php`).
An `EconetPacket` carries:

| Field | Type | Notes |
|---|---|---|
| Source network | `int` | Econet network number |
| Source station | `int` | Econet station number |
| Destination network | `int` | |
| Destination station | `int` | |
| Port | `int` | Econet port byte |
| Flags | `int` | Control byte |
| Data | `string` | Binary payload |

Services never know whether the packet arrived over UDP, a serial cable, or a
WebSocket. The encapsulation layer is the only code that touches raw bytes.

---

## Architecture overview

```
Physical / network medium
        │
   [Handler]             Knows the wire protocol (UDP, serial text, WebSocket)
        │
   [XxxPacket]           Implements EncapsulationInterface
        │  decode()
        │  buildEconetPacket()
        ▼
ServiceDispatcher         Routes by port to providers
        │
        ▼
   [Provider]             File server, print server, …
        │  getReplies()
        ▼
PacketDispatcher          Picks the right transport for each outbound EconetPacket
        │
   EncapsulationTypeMap   Decides: AUN / Piconet / WebSocket
        │
   [Handler].send()       Writes back to the medium
```

---

## EncapsulationInterface

**File:** `src/include/classes/Encapsulation/EncapsulationInterface.php`
**Namespace:** `HomeLan\FileStore\Encapsulation`

Every encapsulation class must implement this interface:

```php
interface EncapsulationInterface {
    public function decode(string $sBinaryString): void;
    public function buildEconetPacket(): EconetPacket;
    public function getPacketType(): string;
    public function getPort(): int;
    public function getData(): string;
    public function toString(): string;
}
```

### `decode(string $sBinaryString): void`

Parse raw bytes from the transport into the object's internal fields (source
address, destination address, port, payload, sequence number, etc.).

### `buildEconetPacket(): EconetPacket`

Convert transport-level addressing to Econet network/station numbers and
return a populated `EconetPacket`. For AUN this means resolving the source IP
address through `Aun\Map`. For Piconet the scout header contains the station
bytes directly. For WebSocket the `WebSocketMap` holds the assigned address.

### `getPacketType(): string`

Return one of the string constants that `ServiceDispatcher` switches on:

| Return value | Meaning |
|---|---|
| `'Broadcast'` | Econet broadcast — delivered to all registered providers |
| `'Unicast'` | Addressed unicast — routed by port |
| `'Ack'` | Acknowledgement frame — triggers ACK-event callbacks |
| `'Immediate'` | Immediate operation (e.g. machine type query) |
| `'ImmediateReply'` | Reply to an immediate — typically not dispatched |
| `'Unknown'` | Dropped silently |

### `getPort(): int` and `getData(): string`

Used by `ServiceDispatcher` for routing and by providers to read the payload.

### `toString(): string`

Debug/log representation. May return any human-readable string.

---

## Existing encapsulations

### AUN — Econet over UDP/IP

**Classes:**
- `HomeLan\FileStore\Aun\AunPacket` — implements `EncapsulationInterface`
- `HomeLan\FileStore\Aun\Handler` — manages the UDP socket, per-host queues, retries
- `HomeLan\FileStore\Aun\Map` — translates IP addresses ↔ Econet network.station pairs

**Wire format** (8-byte header + payload):

| Offset | Size | Field |
|---|---|---|
| 0 | 1 | Type (1=Broadcast, 2=Unicast, 3=Ack, 4=Reject, 5=Immediate, 6=ImmediateReply) |
| 1 | 1 | Econet port |
| 2 | 1 | Control byte |
| 3 | 1 | Padding |
| 4 | 4 | Sequence number (little-endian) |
| 8 | … | Payload |

`Handler::receive()` is called by the ReactPHP UDP datagram event. It:
1. Creates an `AunPacket`, sets its source/dest IP addresses, calls `decode()`.
2. For ACK packets: calls `_unQueue()` to advance the outbound per-host queue.
3. For all others: deduplicates by `sourceIP + sequenceNumber`, sends an ACK
   immediately if needed, and calls `ServiceDispatcher::inboundPacket()`.

`Handler::send()` accepts an `EconetPacket`, resolves the destination address
through `Aun\Map`, and appends the framed packet to a per-host queue.
`Handler::timer()` (called every ~40 ms) drains each queue with configurable
retry logic.

`Aun\Map` is a static class loaded from `aunmap_file`. It maps IP addresses to
Econet addresses for inbound translation, and Econet addresses back to IP for
outbound delivery.

### Piconet — physical Econet via EconetUSB

**Classes:**
- `HomeLan\FileStore\Piconet\PiconetPacket` — implements `EncapsulationInterface`
- `HomeLan\FileStore\Piconet\Handler` — manages the serial stream
- `HomeLan\FileStore\Piconet\Map` — maps Econet network numbers to the handler

The EconetUSB device speaks a text line protocol over a serial USB stream:

| Line prefix | Meaning |
|---|---|
| `RX_BROADCAST <b64scout>` | Received broadcast |
| `RX_TRANSMIT <b64scout> <b64data>` | Received unicast |
| `RX_IMMEDIATE <b64scout> <b64data>` | Received immediate |
| `TX_RESULT OK` | Hardware ACK'd the last TX |
| `TX_RESULT <error>` | Last TX failed |
| `STATUS …` | Device status line |

The scout frame (base64-decoded) is 6 bytes:
`[DstStn][DstNet][SrcStn][SrcNet][ControlByte][Port]`.

`Handler::onMessage()` splits incoming data on newlines and dispatches each
line. Packets are built into `PiconetPacket` objects, decoded, and fed to
`ServiceDispatcher`. Outbound packets are queued and sent as
`TX <stn> <net> <flags> <port> <b64data>` lines.

`Piconet\Map` lists which Econet network numbers are reachable through the
hardware. It is a simple set of integers loaded from `piconetmap_file`.

### WebSocket — Econet for browser emulators

**Classes:**
- `HomeLan\FileStore\WebSocket\JsonPacket` — implements `EncapsulationInterface`
- `HomeLan\FileStore\WebSocket\Handler` — implements `Ratchet\MessageComponentInterface`
- `HomeLan\FileStore\WebSocket\Map` — dynamically allocates network.station to connections

When a browser client connects it sends a `ctrl` message requesting an address.
`Map::allocateAddress()` assigns the next free station in the configured
network range. Subsequent `pkt` messages carry the JSON-encoded payload.

`Handler::onMessage()` decodes `JsonPacket` objects and routes them through
`ServiceDispatcher`. Replies are sent back to the specific `ConnectionInterface`
object, looked up via `Map::ecoAddrToSocket()`.

---

## Inbound packet flow (step by step)

1. **ReactPHP fires a transport event** (UDP message, serial data line, WebSocket
   message) and calls the appropriate handler method.
2. **The handler constructs a `XxxPacket`**, sets any transport-specific
   fields (IP addresses, connection object), and calls `decode()`.
3. **The handler calls `ServiceDispatcher::inboundPacket($oXxxPacket)`**.
4. `ServiceDispatcher` calls `$oXxxPacket->getPacketType()` and switches:
   - `'Unicast'` / `'Immediate'` → `$this->aPorts[$port]->unicastPacketIn($oXxxPacket->buildEconetPacket())`
   - `'Broadcast'` → same method on every registered provider
   - `'Ack'` → `$this->ackEvents($oXxxPacket)` (triggers any waiting ACK callbacks)
5. After calling the provider, `ServiceDispatcher` drains `$oProvider->getReplies()`,
   places each `EconetPacket` on a queue, and calls `PacketDispatcher::sendPacket()`
   on each one.

---

## Outbound packet flow (step by step)

1. A provider calls `$this->addReplyToBuffer($oEconetPacket)` (in the file
   server) or adds a packet to its internal reply buffer.
2. `ServiceDispatcher` calls `PacketDispatcher::sendPacket($oEconetPacket)`.
3. **`EncapsulationTypeMap::getType($oEconetPacket)`** checks in priority order:
   1. `WebSocketMap::ecoAddrToSocket($dstNet, $dstStn)` — if a WebSocket
      client owns that address, return `'WebSocket'`.
   2. `PiconetMap::networkKnown($dstNet)` — if the network is in the piconet
      map, return `'Piconet'`.
   3. Otherwise return `'AUN'`.
4. `PacketDispatcher` calls the appropriate handler's `send()` method.

---

## The event loop wiring

**File:** `src/include/classes/Command/React.php`
**Class:** `HomeLan\FileStore\Command\React` (extends Symfony `Command`)

The `MainLoop()` method (called from `execute()`) wires everything together:

```php
$oLoop = ReactFactory::create();
$oEncapsulationTypeMap = EncapsulationTypeMap::create();
$oPacketDispatcher     = PacketDispatcher::create($oEncapsulationTypeMap, $oLoop);

$oAunHandler     = $this->aunService($oLoop, $oPacketDispatcher);
$oWebSocket      = $this->websocketService($oLoop, $oPacketDispatcher);
$oPiconetHandler = $this->piconetService($oLoop, $oPacketDispatcher);

$oServices->start($oEncapsulationTypeMap, $oLoop);
$oLoop->run();
```

Each `*Service()` helper method:
- Creates the handler / server object.
- Registers it with the ReactPHP loop (UDP socket, TCP server, or serial device).
- Calls the encapsulation's Map `init()` method to load the address map.
- Returns the handler so it can be referenced by timers.

Periodic timers registered in `MainLoop()` drive housekeeping and the AUN
retry queue:

| Period | What it does |
|---|---|
| `AUN_PKT_DELAY` (~40 ms) | `$oAunHandler->timer()` — processes outbound queues with retry/backoff |
| 1 second | Drains `$oServices->getReplies()` (for async replies) |
| `housekeeping_interval` | `Security::houseKeeping()`, `Vfs::houseKeeping()`, `$oServices->houseKeeping()` |

---

## Adding a new encapsulation type

The following steps implement a complete new transport. The example below uses
a hypothetical "FooNet" transport carried over TCP.

### Step 1 — Create the packet class

Create `src/include/classes/FooNet/FooNetPacket.php` implementing
`EncapsulationInterface`:

```php
namespace HomeLan\FileStore\FooNet;

use HomeLan\FileStore\Encapsulation\EncapsulationInterface;
use HomeLan\FileStore\Messages\EconetPacket;

class FooNetPacket implements EncapsulationInterface
{
    private string $sType    = 'Unknown';
    private int    $iPort    = 0;
    private string $sData   = '';
    private int    $iSrcNet  = 0;
    private int    $iSrcStn  = 0;

    public function decode(string $sBinaryString): void
    {
        // Parse FooNet wire bytes into $this->sType, $this->iPort,
        // $this->sData, source addressing, etc.
    }

    public function buildEconetPacket(): EconetPacket
    {
        $oPacket = new EconetPacket();
        $oPacket->setSourceNetwork($this->iSrcNet);
        $oPacket->setSourceStation($this->iSrcStn);
        // … set destination, port, flags, data …
        return $oPacket;
    }

    public function getPacketType(): string { return $this->sType; }
    public function getPort(): int          { return $this->iPort; }
    public function getData(): string       { return $this->sData; }
    public function toString(): string      { return "FooNet pkt port={$this->iPort}"; }
}
```

### Step 2 — Create the address map

Create `src/include/classes/FooNet/Map.php` as a static class:

```php
namespace HomeLan\FileStore\FooNet;

class Map
{
    private static array $aNetworks = [];
    private static ?Handler $oHandler = null;

    public static function init(\Psr\Log\LoggerInterface $oLogger, Handler $oHandler): void
    {
        self::$oHandler = $oHandler;
        // Load network numbers from config / map file
    }

    public static function networkKnown(int $iNet): bool
    {
        return in_array($iNet, self::$aNetworks, true);
    }

    public static function getHandler(): ?Handler
    {
        return self::$oHandler;
    }

    // Add whatever address-lookup methods your transport needs
}
```

### Step 3 — Create the handler

Create `src/include/classes/FooNet/Handler.php`. The handler is responsible
for accepting connections, reading raw frames, calling `decode()`, forwarding
to `ServiceDispatcher`, and sending outbound `EconetPacket` objects.

```php
namespace HomeLan\FileStore\FooNet;

use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Encapsulation\PacketDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;

class Handler
{
    public function __construct(
        private \Psr\Log\LoggerInterface $oLogger,
        private ServiceDispatcher        $oServices,
        private PacketDispatcher         $oPacketDispatcher,
    ) {}

    // Called when data arrives from the transport
    public function onData(string $sRawData): void
    {
        $oPacket = new FooNetPacket();
        $oPacket->decode($sRawData);
        $this->oServices->inboundPacket($oPacket);

        // Drain replies
        foreach ($this->oServices->getReplies() as $oReply) {
            $this->oPacketDispatcher->sendPacket($oReply);
        }
    }

    public function send(EconetPacket $oPacket, int $iRetries = 3): void
    {
        // Convert $oPacket to FooNet wire bytes and write to the transport
    }
}
```

### Step 4 — Register in EncapsulationTypeMap

**File:** `src/include/classes/Encapsulation/EncapsulationTypeMap.php`

Add a check for your transport **before** the AUN default at the end of
`getType()`:

```php
public function getType(EconetPacket $oPacket): string
{
    if (WebSocketMap::ecoAddrToSocket(...)) { return 'WebSocket'; }
    if (PiconetMap::networkKnown(...))      { return 'Piconet'; }
    if (FooNetMap::networkKnown(...))        { return 'FooNet'; }   // ← add this
    return 'AUN';
}
```

### Step 5 — Register in PacketDispatcher

**File:** `src/include/classes/Encapsulation/PacketDispatcher.php`

Add a dispatch branch in `sendPacket()`:

```php
public function sendPacket(EconetPacket $oPacket): void
{
    switch ($this->oTypeMap->getType($oPacket)) {
        case 'WebSocket': …
        case 'Piconet':   …
        case 'FooNet':                                      // ← add this
            $oHandler = FooNetMap::getHandler();
            if ($oHandler !== null) {
                $oHandler->send($oPacket);
            }
            break;
        default: // AUN
            AunMap::getHandler()->send($oPacket);
    }
}
```

### Step 6 — Wire into the event loop

**File:** `src/include/classes/Command/React.php`

Add a `foonetService()` helper and call it from `MainLoop()`:

```php
private function foonetService(
    \React\EventLoop\LoopInterface $oLoop,
    PacketDispatcher               $oPacketDispatcher,
): Handler {
    $oFooNetHandler = new FooNet\Handler($this->oLogger, $this->oServices, $oPacketDispatcher);

    // Wire into ReactPHP using whichever connector fits:
    // TCP: $oLoop->addReadStream($socket, function() use ($oFooNetHandler) { … });
    // Serial: similar to the Piconet UnixSerialDeviceConnector pattern

    FooNet\Map::init($this->oLogger, $oFooNetHandler);
    return $oFooNetHandler;
}
```

Then in `MainLoop()`:

```php
$oFooNetHandler = $this->foonetService($oLoop, $oPacketDispatcher);

// Optionally add a timer if your transport needs periodic processing:
// $oLoop->addPeriodicTimer(0.04, fn() => $oFooNetHandler->timer());
```

### Summary of files to create or modify

| Action | File |
|---|---|
| **Create** | `src/include/classes/FooNet/FooNetPacket.php` |
| **Create** | `src/include/classes/FooNet/Map.php` |
| **Create** | `src/include/classes/FooNet/Handler.php` |
| **Modify** | `src/include/classes/Encapsulation/EncapsulationTypeMap.php` — add type check |
| **Modify** | `src/include/classes/Encapsulation/PacketDispatcher.php` — add dispatch branch |
| **Modify** | `src/include/classes/Command/React.php` — wire into event loop |

No changes are required in any provider (`FileServer`, `PrintServer`, …) or in
`ServiceDispatcher`. Services are completely decoupled from transport.

---

## Key files at a glance

| File | Role |
|---|---|
| `src/include/classes/Encapsulation/EncapsulationInterface.php` | The interface every encapsulation must implement |
| `src/include/classes/Encapsulation/EncapsulationTypeMap.php` | Decides which transport an outbound packet uses |
| `src/include/classes/Encapsulation/PacketDispatcher.php` | Sends outbound `EconetPacket` to the right handler |
| `src/include/classes/Messages/EconetPacket.php` | The transport-neutral packet representation |
| `src/include/classes/Command/React.php` | Event loop wiring for all transports |
| `src/include/classes/Aun/AunPacket.php` | AUN implementation of `EncapsulationInterface` |
| `src/include/classes/Aun/Handler.php` | AUN UDP handler with per-host queue and retries |
| `src/include/classes/Aun/Map.php` | IP ↔ Econet address translation for AUN |
| `src/include/classes/Piconet/PiconetPacket.php` | Piconet (EconetUSB serial) implementation |
| `src/include/classes/Piconet/Handler.php` | Serial device handler for Piconet |
| `src/include/classes/Piconet/Map.php` | Network number set for Piconet routing |
| `src/include/classes/WebSocket/JsonPacket.php` | WebSocket JSON implementation |
| `src/include/classes/WebSocket/Handler.php` | Ratchet WebSocket handler |
| `src/include/classes/WebSocket/Map.php` | Dynamic network.station allocation for WebSocket clients |

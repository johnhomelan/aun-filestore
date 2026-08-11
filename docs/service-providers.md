# Service Providers and the Admin System — Developer Guide

This document describes how service providers work, how they are registered,
how packets are routed to them, and how to add a new provider complete with
admin web-front-end support.

---

## What a provider is

A **service provider** is a class that implements
`HomeLan\FileStore\Services\ProviderInterface` and handles Econet traffic for
one or more **port numbers**. Ports in Econet are single-byte values (0–255)
that identify services the same way TCP ports identify services on IP.

The server registers every provider at startup by its port numbers. All
further traffic dispatching is port-based — no provider ever names another
provider directly.

---

## ProviderInterface

**File:** `src/include/classes/Services/ProviderInterface.php`
**Namespace:** `HomeLan\FileStore\Services`

```php
interface ProviderInterface {
    public function getName(): string;
    public function getAdminInterface(): ?AdminInterface;
    public function unicastPacketIn(EconetPacket $oPacket): void;
    public function broadcastPacketIn(EconetPacket $oPacket): void;
    public function getServicePorts(): array;
    public function registerService(ServiceDispatcher $oServiceDispatcher): void;
    public function getJobs(): array;
    public function getReplies(): array;
}
```

### Method by method

| Method | When called | What it should do |
|---|---|---|
| `getName()` | Admin UI listing | Return a short human-readable name |
| `getAdminInterface()` | Admin UI | Return an `AdminInterface` instance, or `null` if no admin support |
| `getServicePorts()` | Startup registration | Return `int[]` of Econet port numbers to claim |
| `registerService(ServiceDispatcher)` | Startup, once | Store the dispatcher reference; call `$oServiceDispatcher->addHousingKeepingTask(callable)` for any periodic work |
| `unicastPacketIn(EconetPacket)` | Each addressed packet | Process the packet and accumulate replies via `addReplyToBuffer()` |
| `broadcastPacketIn(EconetPacket)` | Each broadcast | Same, for broadcast traffic |
| `getReplies()` | After every `*PacketIn()` call, and on the 1-second timer | Return all pending `EconetPacket[]` and clear the buffer |
| `getJobs()` | Admin UI | Return an array describing in-progress jobs (structure is provider-specific) |

---

## Registration and routing

**File:** `src/include/classes/Services/ServiceDispatcher.php`

Providers are instantiated in `src/filestored` (the main entry script) and
passed as an array to `ServiceDispatcher::create()`:

```php
ServiceDispatcher::create($oLogger, [
    new FileServer($oLogger),
    new PrintServer($oLogger),
    new Bridge($oLogger),
    new IPv4($oLogger),
    new BeebTerm($oLogger),
    new Torchnet($oLogger),
]);
```

`ServiceDispatcher::addService()` calls `registerService()` on the provider
and then calls `enableService()`, which populates an internal
`$aPorts[portNumber] => $oProvider` lookup. Port conflicts (two providers
claiming the same port) throw an exception at startup.

**Inbound routing** is in `ServiceDispatcher::inboundPacket()`:

```php
switch ($oPacket->getPacketType()) {
    case 'Unicast':
    case 'Immediate':
        $this->aPorts[$port]->unicastPacketIn($oEconetPacket);
        // drain getReplies() → queue for sending
        break;

    case 'Broadcast':
        foreach ($this->aProviders as $oProvider) {
            $oProvider->broadcastPacketIn($oEconetPacket);
            // drain getReplies()
        }
        break;

    case 'Ack':
        $this->ackEvents($oPacket);   // triggers registered callbacks
        break;
}
```

If no provider is registered on a port the packet is silently dropped.

---

## ACK events and streaming

Several Econet operations (GETBYTES, PUTBYTES, LOAD) are multi-packet: the
server sends one data block, waits for a client ACK, then sends the next. The
`ServiceDispatcher` provides a callback mechanism for this:

```php
$oServiceDispatcher->addAckEvent(
    int   $iNetwork,
    int   $iStation,
    callable $fCallback    // called when the next ACK arrives from that station
);
```

When an ACK packet arrives, `ServiceDispatcher::ackEvents()` looks up
registered callbacks for the source network/station, calls them, and removes
them. ACK events are one-shot — the callback must re-register itself if more
ACKs are expected.

---

## Existing providers

| Class | Ports | Description |
|---|---|---|
| `FileServer` | `0x99`, `econet_data_stream_port` | Acorn Level 4 file server protocol |
| `PrintServer` | `0x9F`, `0xD1` | Econet print spooler |
| `Bridge` | `0x9C`, `0x9D` | Bridge query/reply protocol |
| `IPv4` | several | EconetA IPv4 forwarding, ARP, NAT, ICMP |
| `BeebTerm` | — | SJ Research BeebTerm terminal sessions |
| `Torchnet` | `0x90`, `0x91` | TorchNet CP/M file services |

---

## Writing a new provider

### Minimum implementation

```php
namespace HomeLan\FileStore\Services\Provider;

use HomeLan\FileStore\Services\ProviderInterface;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Messages\EconetPacket;

class MyService implements ProviderInterface
{
    private ServiceDispatcher $oServiceDispatcher;
    private array $aReplies = [];

    public function getName(): string { return 'My Service'; }

    public function getAdminInterface(): ?AdminInterface { return null; }

    public function getServicePorts(): array { return [0xAB]; }

    public function registerService(ServiceDispatcher $oServiceDispatcher): void
    {
        $this->oServiceDispatcher = $oServiceDispatcher;
        // Register periodic work if needed:
        // $oServiceDispatcher->addHousingKeepingTask(fn() => $this->houseKeeping());
    }

    public function unicastPacketIn(EconetPacket $oPacket): void
    {
        // Decode $oPacket->getData(), build a reply …
        $oReply = new EconetPacket();
        $oReply->setDestinationNetwork($oPacket->getSourceNetwork());
        $oReply->setDestinationStation($oPacket->getSourceStation());
        $oReply->setPort($oPacket->getSourcePort());   // or your reply port
        $oReply->setData($yourResponseBytes);
        $this->aReplies[] = $oReply;
    }

    public function broadcastPacketIn(EconetPacket $oPacket): void {}

    public function getReplies(): array
    {
        $aReplies = $this->aReplies;
        $this->aReplies = [];
        return $aReplies;
    }

    public function getJobs(): array { return []; }
}
```

### Registering the provider

Add an instantiation line to `src/filestored`:

```php
ServiceDispatcher::create($oLogger, [
    // … existing providers …
    new MyService($oLogger),
]);
```

---

## The Admin system

### Overview

Each provider can optionally expose an `AdminInterface` object. The web admin
front end (served from `webadmin_listen_address:webadmin_listen_port`, default
`http://<host>:8080`) uses this interface to:

1. Display a table of data entities (logged-in sessions, open streams,
   user accounts, print jobs, …).
2. Offer an enable/disable toggle for the service.
3. Render action buttons that link to provider-specific pages (file browser,
   download links, …).

The web admin application is a Symfony micro-app embedded in
`src/include/classes/Admin/`. It is wired into the ReactPHP event loop and
served over `React\Http\HttpServer`.

### AdminInterface

**File:** `src/include/classes/Services/Provider/AdminInterface.php`

```php
interface AdminInterface {
    public function getName(): string;
    public function getDescription(): string;
    public function isDisabled(): bool;
    public function setDisabled(): void;
    public function setEnabled(): void;
    public function getStatus(): string;
    public function getEntityTypes(): array;
    public function getEntityFields(string $sType): array;
    public function getEntities(string $sType): array;
    public function getCommands(): array;
}
```

### Method by method

**`getName()` / `getDescription()`**

Human-readable strings shown in the admin index.

**`isDisabled()` / `setDisabled()` / `setEnabled()` / `getStatus()`**

The toggle shown in the admin index. `setDisabled()` must call
`ServiceDispatcher::create()->disableService($this->oProvider)`, which removes
the provider's ports from the routing table so packets stop arriving.
`setEnabled()` calls `enableService()` to restore them. `isDisabled()` and
`getStatus()` reflect the current local state.

Standard implementation (copy for any new Admin class):

```php
private bool $bEnabled = true;

public function isDisabled(): bool { return !$this->bEnabled; }

public function setDisabled(): void
{
    ServiceDispatcher::create()->disableService($this->oProvider);
    $this->bEnabled = false;
}

public function setEnabled(): void
{
    ServiceDispatcher::create()->enableService($this->oProvider);
    $this->bEnabled = true;
}

public function getStatus(): string
{
    return $this->bEnabled ? 'On-line' : 'Disabled';
}
```

**`getEntityTypes(): array`**

Returns a map of `typeKey → 'Human Label'`. Each key becomes a tab in the
service admin page. Example from `FileServer\Admin`:

```php
public function getEntityTypes(): array
{
    return [
        'session' => 'Logged in users',
        'stream'  => 'File Streams',
        'user'    => 'Users',
    ];
}
```

**`getEntityFields(string $sType): array`**

Returns a map of `fieldName → phptype` for the given type. The admin UI uses
this to decide how to render each column. Supported type strings:

| Type string | Rendered as |
|---|---|
| `'int'` | Integer |
| `'string'` | String |
| `'datetime'` | Formatted date/time |
| `'download'` | Download link (value must be a URL) |

Example from `PrintServer\Admin`:

```php
public function getEntityFields(string $sType): array
{
    return match ($sType) {
        'printers' => ['name' => 'string', 'description' => 'string', 'enabled' => 'bool', 'behavior' => 'string', 'allowed_users' => 'string'],
        'jobs'     => ['network' => 'int', 'station' => 'int', 'began' => 'datetime', 'size' => 'int', 'printer' => 'string'],
        'spooled'  => ['printer' => 'string', 'user' => 'string', 'filename' => 'string', 'size' => 'int', 'modified' => 'datetime', 'download' => 'download'],
        default    => [],
    };
}
```

**`getEntities(string $sType): array`**

Returns an `AdminEntity[]` for the given type. Use
`AdminEntity::createCollection()` to build the array:

```php
AdminEntity::createCollection(
    string   $sType,
    array    $aFields,      // from getEntityFields()
    array    $aRows,        // array of assoc-arrays, one per row
    ?callable $fComputeId,  // callable(array $aRow): mixed — computes the entity's ID
    ?string  $sIdField      // OR: use this key from $aRow as the ID
)
```

Only one of `$fComputeId` or `$sIdField` needs to be provided (pass `null`
for the other). The ID is used by the admin UI to identify entities when
deleting or acting on individual rows.

Example from `FileServer\Admin` — sessions tab:

```php
case 'session':
    $aUsers = Security::getUsersOnline();
    $aUserData = [];
    foreach ($aUsers as $iNetwork => $aStationData) {
        foreach ($aStationData as $iStation => $aData) {
            $aUserData[] = [
                'network' => $iNetwork,
                'station' => $iStation,
                'user'    => $aData['user']->getUsername(),
            ];
        }
    }
    return AdminEntity::createCollection(
        $sType,
        $this->getEntityFields($sType),
        $aUserData,
        null,
        'user'   // use the 'user' field as the entity ID
    );
```

Example from `PrintServer\Admin` — using a callable ID:

```php
case 'jobs':
    $aJobs = $this->oProvider->getJobs();
    return AdminEntity::createCollection(
        $sType,
        $this->getEntityFields($sType),
        $aJobs,
        fn($aRow) => $aRow['network'] . '_' . $aRow['station'],
        null
    );
```

**`getCommands(): array`**

Returns navigation links rendered as action buttons on the service admin page.
Each entry is `['label' => '…', 'url' => '/some/path']`. Return an empty
array if no extra navigation is needed.

Example from `FileServer\Admin`:

```php
public function getCommands(): array
{
    $aPorts = $this->oProvider->getServicePorts();
    return [
        ['label' => 'Browse File System', 'url' => '/service/fileserver/browse?port=' . $aPorts[0]],
    ];
}
```

---

## Adding admin support to a new provider

### Step 1 — Create the Admin class

Create `src/include/classes/Services/Provider/MyService/Admin.php`:

```php
namespace HomeLan\FileStore\Services\Provider\MyService;

use HomeLan\FileStore\Services\Provider\AdminInterface;
use HomeLan\FileStore\Services\Provider\AdminEntity;
use HomeLan\FileStore\Services\ServiceDispatcher;
use HomeLan\FileStore\Services\Provider\MyService;

class Admin implements AdminInterface
{
    private bool $bEnabled = true;

    public function __construct(private readonly MyService $oProvider) {}

    public function getName(): string        { return 'My Service'; }
    public function getDescription(): string { return 'Does something useful.'; }

    public function isDisabled(): bool { return !$this->bEnabled; }

    public function setDisabled(): void
    {
        ServiceDispatcher::create()->disableService($this->oProvider);
        $this->bEnabled = false;
    }

    public function setEnabled(): void
    {
        ServiceDispatcher::create()->enableService($this->oProvider);
        $this->bEnabled = true;
    }

    public function getStatus(): string
    {
        return $this->bEnabled ? 'On-line' : 'Disabled';
    }

    public function getEntityTypes(): array
    {
        return ['widget' => 'Active Widgets'];
    }

    public function getEntityFields(string $sType): array
    {
        return match ($sType) {
            'widget' => ['id' => 'int', 'name' => 'string', 'created' => 'datetime'],
            default  => [],
        };
    }

    public function getEntities(string $sType): array
    {
        if ($sType === 'widget') {
            $aRows = $this->oProvider->getWidgets();   // your provider method
            return AdminEntity::createCollection($sType, $this->getEntityFields($sType), $aRows, null, 'id');
        }
        return [];
    }

    public function getCommands(): array { return []; }
}
```

### Step 2 — Return the Admin from the provider

In `MyService.php`:

```php
public function getAdminInterface(): ?AdminInterface
{
    return new MyService\Admin($this);
}
```

The admin object is constructed lazily on first access, so there is no cost
when the admin UI is not in use.

---

## Provider-specific admin pages (optional)

The standard entity table is sufficient for most services. When a service
needs a richer interface (for example a file browser), the following additional
work is required.

### Add extra methods to the provider

These methods are **not** part of `ProviderInterface` or `AdminInterface` —
the admin controller accesses them by type-checking or casting the provider
object directly. Look at `FileServer::getAdminDirectoryListing()` and
`FileServer::getAdminFileContents()` as examples.

```php
// In MyService.php
public function getAdminWidgetDetail(int $iWidgetId): array
{
    // Return data for the widget detail page
}
```

### Create a Symfony controller

Controllers live in `src/include/classes/Admin/Controller/`. Create
`MyServiceController.php`:

```php
namespace HomeLan\FileStore\Admin\Controller;

use HomeLan\FileStore\Services\ServiceDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MyServiceController extends AbstractController
{
    public function detail(Request $oRequest): Response
    {
        $iPort      = (int) $oRequest->query->get('port');
        $iWidgetId  = (int) $oRequest->query->get('widget');
        $oService   = ServiceDispatcher::create()->getServiceByPort($iPort);

        $aDetail = $oService->getAdminWidgetDetail($iWidgetId);

        return $this->render('my_service_detail.tpl', ['detail' => $aDetail]);
    }
}
```

### Register the route

Add an entry to
`src/include/classes/Admin/config/routes.yaml`:

```yaml
myservice_detail:
    path:       /service/myservice/detail
    controller: HomeLan\FileStore\Admin\Controller\MyServiceController::detail
```

### Create a Smarty template

Templates live in `src/include/classes/Admin/templates/`. Create
`my_service_detail.tpl` following the pattern of `fileserver_browse.tpl`.

### Link to the page from `getCommands()`

```php
public function getCommands(): array
{
    $iPort = $this->oProvider->getServicePorts()[0];
    return [
        ['label' => 'Widget Detail', 'url' => '/service/myservice/detail?port=' . $iPort],
    ];
}
```

---

## Admin system architecture

```
Browser HTTP request
        │
React\Http\HttpServer          (in Command\React::adminService())
        │
Symfony Kernel                 (src/include/classes/Admin/Kernel.php)
        │
Routes (Admin/config/routes.yaml)
        │
Controller (Admin/Controller/)
        │
ServiceDispatcher::create()    (singleton — same instance as the running server)
        │
$oProvider->getAdminInterface()
        │
AdminInterface methods         → rendered by Smarty templates
```

`ServiceDispatcher::create()` returns the live singleton, so admin controllers
have direct access to the running state of every provider without any IPC.

---

## Key files at a glance

| File | Role |
|---|---|
| `src/include/classes/Services/ProviderInterface.php` | Interface every provider must implement |
| `src/include/classes/Services/Provider/AdminInterface.php` | Interface for the optional admin view of a provider |
| `src/include/classes/Services/Provider/AdminEntity.php` | Data model for rows in the admin entity tables |
| `src/include/classes/Services/ServiceDispatcher.php` | Routes packets, manages provider lifecycle, runs ACK events |
| `src/include/classes/Command/React.php` | Event loop — add new providers in the constructor call here |
| `src/filestored` | Entry script — instantiate and register providers here |
| `src/include/classes/Admin/Kernel.php` | Symfony micro-app serving the admin web front end |
| `src/include/classes/Admin/config/routes.yaml` | Admin HTTP routes |
| `src/include/classes/Admin/Controller/` | Symfony controllers for each admin page |
| `src/include/classes/Admin/templates/` | Smarty templates for admin pages |
| `src/include/classes/Services/Provider/FileServer/Admin.php` | Reference implementation of AdminInterface |
| `src/include/classes/Services/Provider/PrintServer/Admin.php` | Simpler reference implementation |

### TorchNet Wire Protocol Specification

*Version:* 1.0
*Target Architecture:* Torch Communicator (Z80 CPN / 6502 MCP) over Acorn Econet
*Network Ports:* Primary File Operations: 0x90 | Printing / Extended Services: 0x91 

### 1. Physical and Hardware Frame Layer

Every TorchNet packet wraps inside a standard Acorn Econet frame handled by the Motorola 6854 Advanced Data Link Controller (ADLC). All network multi-byte fields (such as record offsets and addresses) are transmitted in *Little-Endian (LE)* byte order unless specified otherwise. 

### 1.1 The Hardware Network Header

Every frame transmitted across the wire contains a standard 4-byte tracking header at the front of the data payload: 

+-------------+-------------+-------------+-------------+
| Byte 0      | Byte 1      | Byte 2      | Byte 3      |
+-------------+-------------+-------------+-------------+
| DST_STN     | DST_NET     | SRC_STN     | SRC_NET     |
+-------------+-------------+-------------+-------------+

* *DST_STN:* Destination Station ID (1 to 254. 255 = Broadcast).
* *DST_NET:* Destination Network ID (0 for local segment gateways).
* *SRC_STN:* Source Station ID (Station identity of the transmitting machine).
* *SRC_NET:* Source Network ID (Network segment index of the transmitter).

### 1.2 Transmit Mechanics: The Four-Way Handshake

1. *Scout Frame:* Sent by the transmitter. Contains the 4-byte Hardware Header, a Control Flag, and the target Port (0x90 or 0x91).
2. *Scout ACK Frame:* Sent by the receiver if the buffer is ready. Consists of a 4-byte Hardware Header only.
3. *Data Frame:* Sent by the transmitter. Contains the 4-byte Hardware Header followed immediately by the *Data Payload Section* detailed below.
4. *Final ACK Frame:* Sent by the receiver after verifying the 16-bit hardware CRC match. Consists of a 4-byte Hardware Header only.

### 2. File and Directory Operations (Port 0x90)

The Data Payload Section begins with a 1-byte *Command Action Code*. Filenames must strictly adhere to the standard 11-byte space-padded CP/M format (8 bytes name, 3 bytes extension, e.g., "MYPROG  COM"). 

### 2.1 Open File (0x01)

Issued by a client workstation to request an active file handler from a disk-bearing station. 

* *Request Packet Format (Client to Server):* 

+-------------+-------------+-------------+-----------------------+
| Byte 0      | Byte 1      | Byte 2      | Bytes 3-13            |
+-------------+-------------+-------------+-----------------------+
| Cmd (0x01)  | Drive ID    | Access Mode | Filename (8+3 String) |
+-------------+-------------+-------------+-----------------------+

  * Drive ID: ASCII character of the mapped target network drive (e.g., 'E').
  * Access Mode: 0x01 = Read-Only, 0x02 = Write-Only, 0x03 = Shared Read/Write.
* *Response Packet Format (Server to Client):* 

+-------------+-------------+
| Byte 0      | Byte 1      |
+-------------+-------------+
| Status      | File Handle |
+-------------+-------------+

  * Status: 0x00 = Success, 0xFF = File Not Found.
  * File Handle: An arbitrary 1-byte descriptor index (0x00-0xFE) assigned by the server for tracking state.

### 2.2 Close File (0x02)

Flushes unwritten server-side cache allocation maps and de-allocates the active handle. 

* *Request Packet Format (Client to Server):* 

+-------------+-------------+
| Byte 0      | Byte 1      |
+-------------+-------------+
| Cmd (0x02)  | File Handle |
+-------------+-------------+
* *Response Packet Format (Server to Client):* 

+-------------+
| Byte 0      |
+-------------+
| Status      |
+-------------+

  * Status: 0x00 = Success, 0xFF = Handle Invalid or Write Flush Error.

### 2.3 Read Block / Sectors (0x03)

Pulls standard CP/M logical data sectors (128-byte segments) from a targeted file handle. 

* *Request Packet Format (Client to Server):* 

+-------------+-------------+-------------------------+-------------+
| Byte 0      | Byte 1      | Bytes 2-3               | Byte 4      |
+-------------+-------------+-------------------------+-------------+
| Cmd (0x03)  | File Handle | Record Offset (LE Word) | Max Sectors |
+-------------+-------------+-------------------------+-------------+

  * Record Offset: The zero-indexed 16-bit logical sector number to read.
  * Max Sectors: Max number of 128-byte chunks requested (typically 0x01).
* *Response Packet Format (Server to Client):* 

+-------------+-------------+------------------------------------+
| Byte 0      | Byte 1      | Bytes 2 to N                       |
+-------------+-------------+------------------------------------+
| Status      | Length 👎  | Raw Sector Data Data (Max 128 B)   |
+-------------+-------------+------------------------------------+

  * Status: 0x00 = Valid Sector Data, 0x01 = End-of-File (EOF) reached.
  * Length: The actual number of data bytes returned (usually 128 or 0 for an immediate EOF).

### 2.4 Write Block / Sectors (0x04)

Pushes sequential or random 128-byte sector records to the server disk. 

* *Request Packet Format (Client to Server):* 

+-------------+-------------+-------------------------+-------------+-----------------+
| Byte 0      | Byte 1      | Bytes 2-3               | Byte 4      | Bytes 5-132     |
+-------------+-------------+-------------------------+-------------+-----------------+
| Cmd (0x04)  | File Handle | Record Offset (LE Word) | Length (128)| Raw Sector Data |
+-------------+-------------+-------------------------+-------------+-----------------+
* *Response Packet Format (Server to Client):* 

+-------------+
| Byte 0      |
+-------------+
| Status      |
+-------------+

  * Status: 0x00 = Success, 0xFF = Disk Full, Block Locked, or Write-Protected.

### 2.5 Delete / Erase File (0x05)

Removes matching directory headers from the remote drive surface. 

* *Request Packet Format (Client to Server):* 

+-------------+-------------+-------------+-----------------------+
| Byte 0      | Byte 1      | Byte 2      | Bytes 3-13            |
+-------------+-------------+-------------+-----------------------+
| Cmd (0x05)  | Drive ID    | User Group  | Filename (8+3 Mask)   |
+-------------+-------------+-------------+-----------------------+

  * User Group: The CP/M user group directory space index (0 to 15). Wildcards inside the filename string can use standard ASCII ? symbols.
* *Response Packet Format (Server to Client):* 

+-------------+
| Byte 0      |
+-------------+
| Status      |
+-------------+

  * Status: 0x00 = File(s) deleted successfully, 0xFF = No match found or write locked.

### 2.6 Search First (0x06) & Search Next (0x07)

Processes directory listings sequentially over the network wire. 

* *Request Packet Format (Client to Server):* 

+-------------+-------------+-------------+-----------------------+
| Byte 0      | Byte 1      | Byte 2      | Bytes 3-13            |
+-------------+-------------+-------------+-----------------------+
| 0x06 / 0x07 | Drive ID    | User Group  | Filename (8+3 Mask)   |
+-------------+-------------+-------------+-----------------------+

  * Note: Search Next (0x07) must carry identical parameters to ensure sequential state alignment on the server machine.
* *Response Packet Format (Server to Client):* 

+-------------+-----------------------+-------------+-------------------------+
| Byte 0      | Bytes 1-11            | Byte 12     | Bytes 13-16             |
+-------------+-----------------------+-------------+-------------------------+
| Status      | Found Name (8+3 Str)  | Record Count| Allocation Bitmask Data |
+-------------+-----------------------+-------------+-------------------------+

  * Status: 0x00 = Match found (valid metadata fields follow), 0xFF = End of directory reached / No match.
  * Record Count: Specifies the file size in logical records.

### 2.7 Create File (0x0D)

Allocates a fresh slot inside the server's directory allocation structures. 

* *Request Packet Format (Client to Server):* Mapped identically to Open File (0x01).
* *Response Packet Format (Server to Client):* Mapped identically to Open File (0x01), where 0xFF implies a directory bounds overflow.

### 2.8 Rename File (0x0E)

Updates directory string indicators. 

* *Request Packet Format (Client to Server):* 

+-------------+-------------+-----------------------+-----------------------+
| Byte 0      | Byte 1      | Bytes 2-12            | Bytes 13-23           |
+-------------+-------------+-----------------------+-----------------------+
| Cmd (0x0E)  | Drive ID    | Old Name (8+3 String) | New Name (8+3 String) |
+-------------+-------------+-----------------------+-----------------------+
* *Response Packet Format (Server to Client):* 0x00 on successful rename string mutation, 0xFF if original name is absent.

### 3. Supplementary Network Services (Port 0x91 / 0x90)

### 3.1 Network Console Notify / Mail Alert (0x08)

Pushes real-time ASCII text alert updates directly onto remote screens, overriding active console programs. 

* *Request Packet Format (Transmitter to Recipient Node):* 

+-------------+-------------+------------------------------------+
| Byte 0      | Byte 1      | Bytes 2 to N                       |
+-------------+-------------+------------------------------------+
| Cmd (0x08) | Text Length | ASCII Characters (Max 64 bytes)     |
+-------------+-------------+------------------------------------+
```

Emulator Implementation Note: On reception, the server or receiving terminal displays this text block visually without modifying local disk states. No response packet is expected.

3.2 Network Print Redirection (0x09)

Processes redirected CP/M sequential print spool items (LST: stream).

Request Packet Format (Client to Spooler Server):

+-------------+-------------+-------------+------------------------------------+
| Byte 0     | Byte 1     | Byte 2      | Bytes 3 to N                         | 
+-------------+-------------+-------------+------------------------------------+
| Cmd (0x09) | Printer ID | Block Index | Raw Print Stream Data Characters     |
+-------------+-------------+-------------+------------------------------------+ 

Printer ID: Arbitrary destination index (0x00 = System default printer).

Block Index: Incrementing frame counter utilized to verify streaming data continuity.

4. Immediate Administrative Memory Functions

These functions execute at the 6502 Machine Control Program (MCP) interrupt level. They function without triggering internal software polling inside the client's guest Z80 CP/M environment.

4.1 Memory Peek (0x10)

Request Packet Structure:

+-------------+-------------------------+-------------+
| Byte 0     | Bytes 1-2                | Byte 3      | 
+-------------+-------------------------+-------------+ 
| Cmd (0x10) | Target Address (LE Word) | Total Bytes |
+-------------+-------------------------+-------------+ 

Response Structure: Returns Total Bytes extracted directly from the system RAM workspace.

4.2 Memory Poke (0x11)

Request Packet Structure:

+-------------+-------------------------+-------------+----------------------+ 
| Byte 0     | Bytes 1-2                | Byte 3      | Bytes 4 to N         | 
+-------------+-------------------------+-------------+----------------------+ 
| Cmd (0x11) | Target Address (LE Word) | Data Length | Raw Byte Data Arrays | 
+-------------+-------------------------+-------------+----------------------+ 

4.3 Control Actions / Resets (0x1A)

Request Packet Structure:

+-------------+-------------+ 
| Byte 0     | Byte 1       | 
+-------------+-------------+ 
| Cmd (0x1A) | Sub-Action   | 
+-------------+-------------+ 

Sub-Action Codes: 0x00 = Halt System loops, 0x01 = Run System loops, 0x02 = Trigger Hardware Cold Boot.


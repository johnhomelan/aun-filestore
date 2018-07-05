The Code
=
One of the main aims of this project is to produce a codebase that's easy to understand that others can enhance change or modify as they wish, and for it to be used as a research/learning tool for those wanting to research into early LAN protocols. 

Hence this document tries to explain the structure of the code for those who are interested.

The main loop
=
The main loop of the program (src/filestored) sits waiting on all the listening 
sockets (e.g. the AUN socket, the Soap control socket) for activity. Once some activity takes place on a socket, the code jumps to the method to handle that type of activity (e.g. new AUN packet).

AUN Inbound
=
The listening socket for AUN is setup by the filestore class, and upon receiving an AUN packet it create an aunpacket object from that data. 

The aunpacket object then creates the data for an Ack (if needed), and the filestore class sends the ack via it's aun socket.

Any unicast aunpacket is then converted to an econetpacket object for processing.  

Econetpackets are then processed based on their port, so far only the Fileserver,PrinterDiscovery, and PrinterData ports are handled (as only the file and print server applications have been created at the moment).  

Econnetpackets for the fileserver port then have a fsrequest object built and passed to the fileserver object, for the printer server a printserverenquiry or printserverdata object is built.

Fileserver
=
The fileserver object is the main class for handling fsrequests, it makes use of all the security classes and vfs classes.

It's main method processRequest() takes the fsrequest and jumps to a method (of fileserver) based on the fs function in the fsrequest.  Each of the possible fs functions (30 in total) has a method that decodes and performs the requested function and builds a fsreply object (potentially more than one) which is added to fileserver's reply buffer.

Once the fileserver has finished processing the request and all the fsreply objects have been built control passes back to the filestore class.  

The filestore class takes the the fsreply objects converts them to econetpacket objects, works out how these will be sent to the target network/station (usually via AUN).  

If the econetpacket is to be sent via AUN it will convert the econetpacket object to an aunpacket objects and send the packet via it's aun socket.

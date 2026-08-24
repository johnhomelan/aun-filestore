# Builds the SqlServer sample C client (sqlcli.c) with vbcc, for a BBC
# Micro - see sqlcli.c's header comment and docs/protocols/sql-server.md.
#
# vbcc/vasm/vlink aren't expected to be installed on the host - this runs
# them from the bbc-micro-build-env podman image instead, which packages
# vbcc's own 6502-bbc target (crt0, a working C library including stdio,
# bbc.h's OSWORD/OSBYTE helpers, a linker script) - a complete, working BBC
# target unlike cc65's "bbc" (linker memory map only, no crt0/library).
#
# `vc +bbc` drives the whole compile-assemble-link pipeline in one command
# (vbcc6502 -> vasm6502_oldstyle -> vlink), producing SQLCLI (a flat binary
# loading/executing at &1900) and SQLCLI.inf (the DFS-style load/exec sidecar
# *ADFS/*DFS transfer tools use to preserve them) alongside it.
#
# IMAGE can be overridden, e.g. `make IMAGE=bbc-micro-build-env:1.0.0`.
# --network=none: no network access is needed for a local compile, and the
# default podman network (pasta) isn't usable in every environment this
# might run in.

IMAGE  := registry.home-lan.co.uk/bbc-micro-build-env:latest
PODMAN := podman run --rm --network=none -v "$(CURDIR)":/work:Z -w /work $(IMAGE)

TARGET := SQLCLI

.PHONY: all clean

all: $(TARGET)

$(TARGET): sqlcli.c netio.c netio.h
	$(PODMAN) vc +bbc netio.c sqlcli.c -o $(TARGET)

clean:
	rm -f $(TARGET) $(TARGET).inf sqlcli.o netio.o

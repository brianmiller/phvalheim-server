#!/usr/bin/env python3
"""Identity of a client payload's MOD CONTENT, ignoring BepInEx/config.

Why this is not just the md5 of the zip
---------------------------------------
worlds.world_md5 is the md5 of the payload file, and it has to stay that: the client
verifies a finished download against it. But it cannot answer "do I need the 573 MB",
because re-zipping an unchanged tree produces different bytes -- different entry order,
different timestamps -- so every repackage moves it even when not one plugin changed.
A client deciding on that value re-downloads the whole modpack for an 80 KB config edit,
which is exactly what 2.55 shipped doing.

So this computes a second, narrower identity: the per-entry CRC-32s the zip already
stores, for every entry EXCEPT BepInEx/config, hashed in sorted order. Two payloads
built from the same mods agree on it however many times they are re-zipped; change a
plugin, add a mod, or drop one, and it moves.

Read from the central directory only -- no decompression, no reading of the 573 MB of
compressed data. It costs milliseconds on the real payload.

Deliberately NOT a content hash of the files themselves. CRC-32 is weak against a
deliberate collision, and strong enough for this: the question here is "is this the same
build of the same mods", asked of an artifact we produced ourselves. The thing that
actually protects a download is world_md5, which is unchanged and still md5.

Usage:
    payloadKey.py <payload.zip>          prints the key, exit 0
                                         exit 1 and prints nothing on any failure
"""

import hashlib
import sys
import zipfile

# Entries under this prefix are the config generation, tracked separately as
# worlds.config_md5. A trailing slash so a plugin named "configurator" cannot be mistaken
# for part of the config tree.
CONFIG_PREFIX = "BepInEx/config/"


def _normalise(name):
    """Zip entry names, as the archive stores them vs as we want to compare them.

    `zip -r ./BepInEx` writes `BepInEx/...`, but a tree built another way can carry a
    `./` prefix, and Windows-produced archives use backslashes. Comparing raw names would
    make the key depend on which tool built the payload rather than on its contents.
    """
    n = name.replace("\\", "/")
    while n.startswith("./"):
        n = n[2:]
    return n


def mods_key(path):
    h = hashlib.md5()
    rows = []

    with zipfile.ZipFile(path) as z:
        for info in z.infolist():
            name = _normalise(info.filename)

            # Directory entries carry no content and are not always written -- `zip` emits
            # them, some writers do not. Including them would make the key depend on the
            # packaging tool.
            if name.endswith("/"):
                continue
            if name.startswith(CONFIG_PREFIX):
                continue

            # The size is in there as well as the CRC. A CRC-32 collision between two
            # different plugin DLLs is not a hypothetical worth ignoring for free, and the
            # size is already in the directory next to it.
            rows.append("%s\0%08x\0%d" % (name, info.CRC, info.file_size))

    # Sorted, so entry ORDER cannot change the answer. That is the entire point: the order
    # zip happens to walk a directory in is not a property of the mods.
    for row in sorted(rows):
        h.update(row.encode("utf-8", "surrogateescape"))
        h.update(b"\n")

    # An empty payload is not an identity. Returning a hash of nothing would be a real
    # looking value that every empty payload shares, and the caller would store it as
    # though it meant something.
    if not rows:
        return None

    return h.hexdigest()


def main():
    if len(sys.argv) != 2:
        return 1

    try:
        key = mods_key(sys.argv[1])
    except Exception:
        # Unreadable, not a zip, truncated. The caller's contract is "no output means I
        # could not answer", which leaves the previous value in the database rather than
        # clearing it -- a cleared key means "unknown" to the client and costs a full
        # download, so it must not be the result of a transient failure here.
        return 1

    if key is None:
        return 1

    print(key)
    return 0


if __name__ == "__main__":
    sys.exit(main())

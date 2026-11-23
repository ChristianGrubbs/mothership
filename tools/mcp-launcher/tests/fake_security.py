#!/usr/bin/env python3
"""Stands in for macOS `security` in the launcher tests: generic passwords kept in the JSON file FAKE_KEYCHAIN.

Supports the two forms the launcher uses: `find-generic-password -a A -s S -w` and `-i` with
`add-generic-password -a A -s S -U -X <hex>` lines on stdin. Each call is appended to FAKE_KEYCHAIN.calls.
"""
import fcntl
import json
import os
import sys

PATH = os.environ["FAKE_KEYCHAIN"]


def load():
    try:
        with open(PATH, "r", encoding="utf-8") as handle:
            return json.load(handle)
    except FileNotFoundError:
        return {}


def store(items):
    with open(PATH + ".new", "w", encoding="utf-8") as handle:
        json.dump(items, handle)
    os.replace(PATH + ".new", PATH)


def note(line):
    with open(PATH + ".calls", "a", encoding="utf-8") as handle:
        handle.write(line + "\n")


def option(words, flag):
    return words[words.index(flag) + 1]


def main(arguments):
    with open(PATH + ".lock", "a", encoding="utf-8") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if arguments[:1] == ["find-generic-password"]:
            service = option(arguments, "-s")
            note("find " + service)
            value = load().get(option(arguments, "-a") + "/" + service)
            if value is None:
                sys.stderr.write("security: The specified item could not be found in the keychain.\n")
                return 44
            sys.stdout.write(value + "\n")
            return 0
        if arguments == ["-i"]:
            items = load()
            for line in sys.stdin:
                words = line.split()
                if words[:1] == ["add-generic-password"]:
                    service = option(words, "-s")
                    note("add " + service)
                    items[option(words, "-a") + "/" + service] = bytes.fromhex(option(words, "-X")).decode("utf-8")
            store(items)
            return 0
    return 2


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))

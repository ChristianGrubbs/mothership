#!/bin/zsh
# Stand-in for the `container` CLI: answers only the read-only calls the app makes.
case "$1 ${2:-}" in
  "system status") print -r -- "status running" ;;
  "stats --no-stream") print -r -- "[]" ;;
  *) exit 64 ;;
esac

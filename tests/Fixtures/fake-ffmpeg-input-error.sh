#!/usr/bin/env bash
# Reproduces how ffmpeg reports an unreadable RTMP input: the useful line first,
# a generic trailer last. The input URL carries the stream key, as it really does.
src=""
while [[ $# -gt 0 ]]; do
  if [[ "$1" == "-i" ]]; then src="$2"; fi
  shift
done
{
  echo "[rtmp @ 0x55d1] Server error: NetStream.Play.StreamNotFound"
  echo "$src: Input/output error"
  echo "Error opening input files: Input/output error"
} >&2
exit 1

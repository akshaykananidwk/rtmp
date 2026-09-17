#!/usr/bin/env bash
# Fake ffmpeg for tests. Last argument is the target (RTMP URL or file).
# - target containing "fail"  -> exit 1 immediately with an error on stderr
# - target ending in .mp4     -> write bytes to the file and keep running
# - otherwise                 -> emit -progress style output and keep running
target="${@: -1}"
if [[ "$target" == *fail* ]]; then
  echo "[rtmp @ 0x1] Connection to $target failed: Connection refused" >&2
  exit 1
fi
if [[ "$target" == *.mp4 ]]; then
  head -c 200000 /dev/zero > "$target"
fi
size=0
trap 'exit 0' TERM INT
while true; do
  size=$((size + 90000))
  echo "frame=100"
  echo "total_size=$size"
  echo "progress=continue"
  sleep 0.2
done

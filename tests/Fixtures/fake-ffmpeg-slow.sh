#!/usr/bin/env bash
# Emits progress showing an encoder that cannot keep up, then dies the way the media
# server's disconnect surfaces: a broken pipe on the input.
echo "frame=100"
echo "fps=17"
echo "total_size=120000"
echo "speed=0.61x"
echo "progress=continue"
sleep 0.3
echo "[in#0/flv @ 0x1] Error during demuxing: Broken pipe" >&2
exit 1

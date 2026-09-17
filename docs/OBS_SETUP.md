# OBS / vMix / Streamlabs Setup

Get the **Server** and **Stream Key** from *Admin → OBS Setup* (or *Stream Keys*). Keys look like
`AKDWK-XXXXXXXX-XXXXXXXX-XXXXXXXX`. The key is shown once at creation; afterwards use *Show/Copy* (audited).

## OBS Studio

1. Settings → **Stream**
2. Service: **Custom…**
3. Server: `rtmp://stream.example.com/live`
4. Stream Key: `AKDWK-…`
5. Settings → **Output** (Advanced): Encoder x264 or NVENC, Rate control CBR, Bitrate 3500–6000 kbps (1080p30), **Keyframe interval 2 s**, Profile high, Tune zerolatency (optional)
6. Audio: AAC 160 kbps, 48 kHz
7. **Start Streaming** → dashboard shows *Incoming Stream Detected*
8. Press **START LIVE** (manual mode) or enable *Automatic mode* on the stream key / Settings → Streaming

## vMix

Stream → Settings (gear) → Destination **Custom RTMP Server** → URL = Server, Stream Name or Key = key → Quality
1080p30 (4500 kbps), keyframe 2 s → Start.

## Streamlabs Desktop

Settings → Stream → Stream Type **Custom Streaming Server** → URL = Server, Stream key = key → Go Live.

## Hardware encoders / SRT

Any RTMP encoder works. SRT: `srt://stream.example.com:8890?streamid=publish:live/AKDWK-…`.

## Recommended settings

| Target | Resolution | FPS | Video bitrate | Audio |
|---|---|---|---|---|
| Mobile-friendly | 1280×720 | 30 | 2500–3500 kbps | 128 kbps |
| Standard | 1920×1080 | 30 | 4500 kbps | 160 kbps |
| High | 1920×1080 | 60 | 6000–8000 kbps | 160 kbps |

Relays copy the stream without re-encoding (`-c copy`), so what you send is what every platform receives.
Facebook requires ≤ 1080p; YouTube accepts higher. Keyframe interval must be 2 s for YouTube/Facebook.

## Troubleshooting

- *Failed to connect* in OBS → key revoked/disabled, or port 1935 blocked, or MediaMTX down (`systemctl status mediamtx`).
- Stream detected but destinations stay *connecting* → check destination test result and Live Logs; verify platform stream key.
- Regenerate the key if it leaked — old key stops working immediately.

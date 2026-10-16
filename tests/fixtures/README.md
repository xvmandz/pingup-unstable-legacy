`clip.webm` is a generated 12-second test pattern with a sine tone, not third-party content. Used to verify real native playback and seeking. Regenerate with:

```sh
ffmpeg -hide_banner -loglevel error -f lavfi -i 'testsrc2=size=160x90:rate=10' -f lavfi -i 'sine=frequency=440:sample_rate=16000' -t 12 -c:v libvpx -g 10 -b:v 60k -c:a libopus -b:a 12k -y tests/fixtures/clip.webm
```

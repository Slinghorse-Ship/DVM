# Own synthetic reel inputs

The surround inputs are three-second 1080 × 1920, 30 fps AVC clips with real
48-kHz AAC audio. Their original stereo channels are silent; a 440-Hz signal
exists only in the center channel. They are generated test inputs, not recordings
of people. They are selected locally in the editor; the common public reel
profile only accepts the actual converted two-channel output.

- `portrait-avc-center-5.1.mp4`, six channels, SHA-256
  `7c987f21b893f7b02d23bb51d5a1f791e20c9289c5124544d121449414718fe9`.
- `portrait-avc-center-7.1.mp4`, eight channels, SHA-256
  `9a7466942ce252343565da624b69f76cadd87293529a9838694c370f3c8e4ff4`.

Generated with FFmpeg 7.1.5; `ffprobe` independently verified dimensions, duration,
AVC, AAC, 48 kHz and channel count/layout. For 5.1:

```sh
ffmpeg -f lavfi -i color=c=0x163f33:s=1080x1920:r=30:d=3 \
  -f lavfi -i 'aevalsrc=0|0|0.2*sin(2*PI*440*t)|0|0|0:s=48000:d=3:c=5.1' \
  -c:v libx264 -profile:v baseline -level:v 4.0 -pix_fmt yuv420p \
  -preset ultrafast -threads 1 -c:a aac -b:a 192k -movflags +faststart \
  -shortest own-center-only-5.1.mp4
```

For 7.1 use eight channel expressions and `c=7.1`. Fixture generation is a
maintainer step; FFmpeg is not required on the production PHP host.

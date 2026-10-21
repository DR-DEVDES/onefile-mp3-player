# OneFileMP3Player

A responsive, self-hosted web audio player built with PHP, HTML, CSS, and vanilla JavaScript.
Drop `index.php` into a folder containing your audio files and open it through a PHP-enabled web server.

## Features

- Browser-based playback for MP3, OGG, WAV, FLAC, M4A, and OPUS files
- Directory browser with breadcrumb navigation
- Playlist saved in browser `localStorage`
- Search for tracks and directories
- Shuffle, loop, and tree-play options
- Playback progress, seeking, previous/next, and configurable skip buttons
- Reads selected ID3v2 tags: title, artist, album, and embedded cover art
- Album artwork can become the ambient page background
- Responsive dark UI inspired by the Dracula palette
- No JavaScript framework or build step

## Requirements

- PHP 7.4+ recommended; PHP 8.x preferred
- PHP extensions: `mbstring` and `iconv`
- A web server configured to execute PHP
- Read access to the audio files

## Quick start

1. Download or clone this repository.
2. Put `index.php` in the folder containing your music, or in a parent folder.
3. Run locally for testing:

   ```bash
   php -S 127.0.0.1:8000
   ```

4. Open <http://127.0.0.1:8000>.

The PHP built-in server is intended for local development. Use Apache or Nginx for deployment.

## Configuration

Edit the constants at the top of `index.php`:

| Constant | Purpose |
|---|---|
| `PLAYER_TITLE` | Player and browser title |
| `TRACK_AS_TITLE` | Change the browser title to the active track |
| `MIN_SEARCH_STR` | Minimum search query length |
| `MAX_SEARCH_RESULT` | Maximum returned search results |
| `AUDIO_EXTENSIONS` | Allowed audio extensions |
| `DIR_EXCLUDE_ARR` | Directory names to exclude, lowercase |
| `DIR_EXCLUDE_REGEX` | Optional regular expression for excluded directories |
| `SKIP_BUTTONS` | Seek offsets in seconds |
| `SHOW_COVER_DEFAULT` | Default cover-art preference |
| `TXT_ROOT` | Root breadcrumb label |

## Audio library layout

Any nested directory structure is supported. Example:

```text
music/
├── index.php
├── Ambient/
│   ├── track-one.mp3
│   └── track-two.flac
└── Techno/
    └── Live Set/
        └── set.ogg
```

The player lists the supported file types configured in `AUDIO_EXTENSIONS`.

## Security and privacy

This app is designed as a simple directory-based player, not a complete media server.

- Anyone who can access the page may be able to browse and play files under its directory.
- Do not install it in a directory containing private documents or unrelated files.
- Use web-server authentication, VPN access, or IP restrictions for private libraries.
- Keep PHP and the web server patched.
- Treat the player as a self-hosted tool; it does not provide user accounts or access control.
- The `safe_path()` helper provides basic path checks, but it is not a substitute for filesystem permissions and a hardened deployment.
- ID3 metadata and file names are read from files on the server. Only use it with content you trust.

## Known limitations

- Embedded artwork parsing targets common ID3v2 frame formats; not every tag variant is guaranteed.
- Browser codec support varies. A file extension being listed does not guarantee playback in every browser.
- Playlist and playback preferences are stored locally in the current browser.
- Large libraries may make recursive searches slower.

## Contributing

Bug reports and focused pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).

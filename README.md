# Core 2 emulator

Local [Renode](https://renode.io/) model of the Epson S1C31W74 used in Core 2 research, plus a PHP console that draws the segment glass and loads images you supply yourself. It does not download firmware.

```powershell
copy local.properties.example local.properties
php -S 127.0.0.1:8765 -t web
```

Open `http://127.0.0.1:8765`. The API answers only on localhost.

## Disclaimer

This project is an independent open-source tool meant only for research and for developing aftermarket firmware. It is not affiliated with Suunto, and it does not claim any of Suunto's copyrights. All trademarks belong to their owners. You take the full risk of using it, including the chance of bricking a watch.

This repository does not ship Suunto firmware, memory dumps, or proprietary libraries.

## Layout

| Path | What it is |
| --- | --- |
| `platform/` | Renode platform description and the S1C31W74 peripheral model |
| `fw/` | Synthetic Thumb examples (`happy`, `blink`, `core_demo`, `wdt_reset`, `boot0`). Sources only |
| `web/` | PHP console, JSON API, segment-glass view |
| `scripts/` | Renode start and snapshot helpers |
| `tests/` | Boot test for the synthetic images |

## Build and run

Requires PHP 8.2+, GNU Arm Embedded toolchain, and Renode 1.17 portable at `%LOCALAPPDATA%\s1c31-tools\renode\renode_1.17.0-portable\renode.exe`.

```powershell
make -C fw
renode scripts/run.resc
php -S 127.0.0.1:8765 -t web
curl "http://127.0.0.1:8765/api.php?action=meta"
```

Own images stay outside git. Set `corium.dir` and `corium.images` in `local.properties`, or `CORIUM_UNPACKED` and `CORIUM_IMAGES` in the environment. `local.properties` is gitignored. `local.properties.example` has empty values.

## License

[MIT](LICENSE). Copyright (c) 2026 Adam Krůžela.

Suunto and Movesense are trademarks of their owners. This license does not cover those marks, and it does not cover firmware or libraries that are not in this repository.

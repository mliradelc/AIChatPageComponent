# Local ILIAS test instance

A local ILIAS 9.24 with the AIChatPageComponent plugin, for plugin development. It runs with `docker compose` from the repository root and listens only on `http://localhost:8080` (bound to 127.0.0.1).

## Start

```bash
scripts/ilias-dev.sh up
```

`up` starts the stack and rebuilds ILIAS's autoload and artifact files, which the plugin needs (see below). The first start installs ILIAS automatically and takes a few minutes. Follow it with `docker compose logs -f ilias`.

Then open **http://localhost:8080** and sign in.

## Test accounts

| Login | Role | Password |
|---|---|---|
| `root` | ILIAS root administrator | generated on first install, see below |
| `admin` | Administrator (global role) | in `~/.config/aichat-ilias/test-users.json` |
| `tutor1` | regular user | same file |
| `learner1` | regular user | same file |
| `learner2` | regular user | same file |

Passwords are kept outside the repository, in `~/.config/aichat-ilias/` (directory mode 700, files mode 600). The root password is in `~/.config/aichat-ilias/env` (`ILIAS_ROOT_PASSWORD`). Nothing secret is stored in the repository, because the repository is mounted inside ILIAS's web root.

## Plugin

The plugin is not installed automatically. After the first start, install and activate it once under **Administration > Extending ILIAS > Plugins** (row AIChatPageComponent: *Install*, then *Activate*). That state is stored in the database, so it is kept by the volumes and by snapshots. After a reset from scratch, do it again.

Then configure the AI service and the RAG service under **AIChatPageComponent > Configure** (see the README, section "Configuration (administrators)"). API tokens and RAG client keys are entered there and are stored in the ILIAS database, not in the repository.

## Persistence

- Data lives in named Docker volumes (`mysql-data`, `ilias-data`, `ilias-iliasdata`). They survive `docker compose stop/start` and `docker compose down` (without `-v`).
- `docker compose down -v` deletes these volumes. Take a snapshot before doing that.
- The plugin is found through generated files in the ILIAS container (`plugin_data.php`, the Composer classmap). These are not stored in a volume, so after the container is recreated (`down`/`up`), run `scripts/ilias-dev.sh refresh`. `scripts/ilias-dev.sh up` does this for you.

### Snapshots

```bash
scripts/ilias-dev.sh snapshot base      # save database and ILIAS data directories
scripts/ilias-dev.sh restore base       # replace the current state with the snapshot
scripts/ilias-dev.sh list
```

Snapshots are stored in `~/.local/share/aichat-ilias/snapshots/<name>/` (override with `ILIAS_SNAPSHOT_DIR`). They are outside the repository on purpose. They contain the database, including password hashes, and uploaded files. Do not commit them.

`restore` stops ILIAS, imports the database dump, replaces `data/` and the ILIAS data directory, and starts ILIAS again.

## Reset from scratch

```bash
docker compose down -v
scripts/ilias-dev.sh up
```

Then recreate the users (see "Test accounts") or restore a snapshot.

## Notes on the setup

- **Charset:** the MariaDB server uses `utf8` (utf8mb3). With `utf8mb4`, ILIAS's table `crs_settings` exceeds InnoDB's 65535-byte row limit during installation, and the install fails.
- **Plugin install:** the image's automatic setup runs with `ILIAS_INSTALL_ARGUMENTS=--no-plugins`. The plugin is installed afterwards, when ILIAS can see it in `Customizing/`.
- **Root password:** `ILIAS_ROOT_PASSWORD` is only used by the first install. If it is unset, a random password is generated and printed in the log (`docker compose logs ilias`).
- **Exposure:** the repository is mounted into ILIAS's web root, so files such as `docker-compose.yml` and `plugin.php` can be read at `http://localhost:8080/Customizing/global/plugins/Services/COPage/PageComponent/AIChatPageComponent/`. The instance is bound to 127.0.0.1, so only this machine can reach it. Do not put secrets in the repository.

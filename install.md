# Install

There is nothing to install. `tripcal.py` and `merge_kml.py` use the Python
standard library only.

```
cd ~/path/to/tripcal
/usr/bin/python3 tripcal.py <trip-file>
```

`/usr/bin/python3` is the Python that ships with macOS (3.9.x on Tahoe). It
is not managed by Homebrew, not subject to PEP 668 "externally-managed-
environment" errors, and needs no virtualenv, because nothing is ever
installed into it.

## Why no virtualenv

The earlier setup used `requests`, which forced a dependency install. On a
Homebrew Python that fails with `error: externally-managed-environment`, so
the workaround was a `uv`-created `.venv`. That venv's interpreter was a
symlink into `/Volumes/homes/rcvn/.local/share/uv/python/...` — a network
volume. When that path went away, `.venv/bin/python3` became a dangling
symlink. `source .venv/bin/activate` then still "worked" (it only edits
PATH), but `python3` fell through to the system interpreter, which cannot
see `.venv/lib/python3.12/site-packages`, producing a bare
`ModuleNotFoundError: No module named 'requests'` with no indication that
the venv was the problem.

The two `requests.get` calls are now two `urllib.request` calls. The
dependency, the venv, and the whole class of failure are gone.

## Cleanup

The dead venv can be deleted:

```
rm -rf ~/path/to/tripcal/.venv
```

## If you ever do need a third-party package

Do not `pip install` into Homebrew Python. Either vendor the module into the
repo, or create a venv from the system Python, which lives on local disk and
does not disappear:

```
/usr/bin/python3 -m venv .venv
./.venv/bin/python3 -m pip install <pkg>
./.venv/bin/python3 tripcal.py <trip-file>
```

Invoke `./.venv/bin/python3` by path rather than relying on `activate`. If
the interpreter is missing, that fails loudly instead of silently running a
different Python.

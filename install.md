Using this on Mac Tahoe with python installed from homebrew was very problematic. I
installed "uv" from homebrew as a more stable python release to overcome this:

```
brew install uv
cd ~/path/to/your/project
uv venv .venv
source .venv/bin/activate
uv pip install requests
```

then with tripcal.py in the project directory and the venv active, I can run it as documented:

``
python3 tripcal.py <trip-file>
```


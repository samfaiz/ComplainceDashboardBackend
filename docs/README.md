# Documentation

| File | Purpose |
|---|---|
| `DOCUMENTATION.md` / `DOCUMENTATION.pdf` | Technical reference — architecture, schema, every API, deployment |
| `PRD.md` / `PRD.pdf` | Product Requirements Document — vision, personas, user stories, functional + non-functional requirements |
| `build-pdf.mjs` | Standalone markdown → styled HTML converter (no npm deps) used to regenerate the PDFs |

## Regenerate the PDFs

Requires Node 18+ and Google Chrome installed.

```powershell
# From this folder (Backend/docs)
node build-pdf.mjs                      # produces DOCUMENTATION.html
node build-pdf.mjs PRD.md               # produces PRD.html

# Convert each to PDF using headless Chrome
$chrome = "C:\Program Files\Google\Chrome\Application\chrome.exe"
& $chrome --headless=new --disable-gpu "--print-to-pdf=$PWD\DOCUMENTATION.pdf" "file:///$($PWD.Path.Replace('\','/'))/DOCUMENTATION.html"
& $chrome --headless=new --disable-gpu "--print-to-pdf=$PWD\PRD.pdf"           "file:///$($PWD.Path.Replace('\','/'))/PRD.html"
```

On macOS / Linux, swap the Chrome path:

```bash
chrome="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"   # macOS
# or
chrome=$(command -v google-chrome || command -v chromium)               # Linux

node build-pdf.mjs
"$chrome" --headless=new --disable-gpu --print-to-pdf="$(pwd)/DOCUMENTATION.pdf" "file://$(pwd)/DOCUMENTATION.html"
```

The `*.html` intermediate files are gitignored.

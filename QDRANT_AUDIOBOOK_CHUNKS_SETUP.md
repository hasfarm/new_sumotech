# Qdrant Setup For `audiobook_chapter_chunks`

This project now includes a Qdrant sync command that indexes chunk text from SQL table `audiobook_chapter_chunks` into a Qdrant collection.

## 1. Start Qdrant

Option A: Docker (quickest)

```bash
docker run -p 6333:6333 -p 6334:6334 --name qdrant-audiobooks qdrant/qdrant
```

Option B: Existing Qdrant server

- Use your current Qdrant URL and API key in `.env`.

Option C: Native Windows binary (no Docker)

```powershell
# Download latest Windows binary
$release = Invoke-RestMethod -Uri https://api.github.com/repos/qdrant/qdrant/releases/latest
$asset = $release.assets | Where-Object { $_.name -eq 'qdrant-x86_64-pc-windows-msvc.zip' } | Select-Object -First 1
New-Item -ItemType Directory -Force -Path "storage/tools/qdrant" | Out-Null
$zipPath = Join-Path $env:TEMP ("qdrant-" + $release.tag_name + ".zip")
Invoke-WebRequest -Uri $asset.browser_download_url -OutFile $zipPath
Expand-Archive -Path $zipPath -DestinationPath "storage/tools/qdrant" -Force

# Start Qdrant
storage/tools/qdrant/qdrant.exe
```

Quick health check:

```powershell
Test-NetConnection -ComputerName 127.0.0.1 -Port 6333
```

## 2. Configure Environment

Add/update these variables in `.env`:

```env
OPENAI_API_KEY=your_openai_key
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_EMBEDDING_MODEL=text-embedding-3-small

QDRANT_URL=http://127.0.0.1:6333
QDRANT_API_KEY=
QDRANT_COLLECTION=audiobook_chapter_chunks
QDRANT_DISTANCE=Cosine
QDRANT_TIMEOUT=30
QDRANT_OPENAI_TIMEOUT=60
```

Then clear config cache:

```bash
php artisan config:clear
```

## 3. Dry-run Before Indexing

List chunks that would be indexed:

```bash
php artisan qdrant:sync-audiobook-chunks 49 --dry-run
```

## 4. Index Chunks

Index one audiobook:

```bash
php artisan qdrant:sync-audiobook-chunks 49
```

Index all audiobooks:

```bash
php artisan qdrant:sync-audiobook-chunks
```

Index with filters:

```bash
php artisan qdrant:sync-audiobook-chunks --chapter-id=1298
php artisan qdrant:sync-audiobook-chunks --chunk-id=10001
php artisan qdrant:sync-audiobook-chunks 49 --limit=200
```

Recreate collection before sync:

```bash
php artisan qdrant:sync-audiobook-chunks 49 --recreate
```

## 5. Command Summary

The command prints:

- `Indexed`: number of vectors upserted to Qdrant
- `Skipped`: chunks skipped (for example empty text)
- `Failed`: chunks that failed (network/config/validation)

If `Failed > 0`, rerun after fixing the reported errors.

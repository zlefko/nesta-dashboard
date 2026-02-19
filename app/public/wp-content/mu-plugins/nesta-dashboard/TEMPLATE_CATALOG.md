# Remote Template Catalog (getnesta.com)

The MU plugin can sync templates from a remote catalog. By default it reads:

- `https://getnesta.com/nesta-templates/templates.json`

You can override the URL with the `nesta_template_catalog_url` filter.

## Recommended folder layout on getnesta.com

```
/nesta-templates/
  templates.json
  templates/
    rugged.zip
    classic.zip
    modern.zip
```

## templates.json format

```
{
  "version": 1,
  "templates": [
    {
      "id": "rugged",
      "name": "Rugged",
      "version": "1.1.0",
      "download_url": "https://getnesta.com/nesta-templates/templates/rugged.zip",
      "checksum": "sha256-hex-string"
    }
  ]
}
```

- `id` must be unique.
- `version` should be bumped when you change a template.
- `checksum` is optional but recommended (SHA-256 of the zip). It forces updates even if the version stays the same.

## Zip contents

Each template zip should include:

```
manifest.json
screenshot.jpeg (or screenshot.png)
bundle/export.xml
pages/...
settings/...
```

The zip should not wrap everything in an extra top-level folder (the manifest must be at the zip root).

### Shared uploads.zip (recommended)

If you want all templates to use the shared media bundle, set this in `manifest.json`:

```
"bundle": {
  "export": "bundle/export.xml",
  "uploads": "../shared/uploads.zip"
}
```

When using the shared bundle, you can omit `bundle/uploads.zip` from the zip entirely.
The MU plugin will look for a shared copy at:

- `wp-content/uploads/nesta-shared/uploads.zip`

If it does not exist yet, it will fall back to the bundled file at:

- `app/public/wp-content/mu-plugins/nesta-dashboard/templates/shared/uploads.zip`

If neither file exists, the MU plugin will download the shared bundle from:

- `https://getnesta.com/nesta-templates/shared/uploads.zip`

## Workflow

1) Build the template locally and zip the template folder contents.
2) Upload the zip to `getnesta.com/nesta-templates/templates/`.
3) Update `templates.json` with the new entry (or version/checksum change).
4) Click **Sync templates** in the Nesta Quick Start screen.

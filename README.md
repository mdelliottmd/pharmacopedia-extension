# Pharmacopedia

MediaWiki extension powering [pharmacopedia.wiki](https://pharmacopedia.wiki) —
a wiki of medicines with structured profiles, drug-interaction tracking,
contributor-submitted effect and experience reports, embedded psychological
assessments, and a sysop toolkit for problems/diagnoses curation.

## License

GPL v3. See [`LICENSE`](LICENSE).

## Status

Active development. The wiki it powers is live; the extension is moving
quickly. The current deployment version is recorded in
[`extension.json`](extension.json) (`version` field).

## Installation

Standard MediaWiki extension layout. Drop into `extensions/Pharmacopedia/` of
your MediaWiki install and load with:

```php
wfLoadExtension( 'Pharmacopedia' );
```

in `LocalSettings.php`. The extension targets MediaWiki ≥ 1.45.

## Deployment notes

Several runtime directories under `/var/lib/pharmacopedia-*` are referenced by
default in `extension.json` and can be overridden per-install via the
corresponding `$wgPCP*` config settings. The MediaWiki user (typically
`www-data`) needs write access to these.

Optional WHO ICD-API integration (for diagnosis autocomplete) reads OAuth2
credentials from `/etc/pharmacopedia-icd-api.env` (recommended permissions:
mode 600, owned by root). Without this file, diagnosis lookup falls back to
the locally-ingested ICD-10 dataset.

## Issues & contributions

Open an issue or pull request on this repository.

# RDLSolrSearch

Site-wide OMP 3.4 plugin that replaces OMP's database search with a
standalone Solr core.

Solr runs as its own service and is never installed inside the OMP
directory. By default the plugin talks to `http://127.0.0.1:8983/solr`,
core `omp`.

## Status

- Phase 1: plugin skeleton, site-wide settings, Solr client and a
  connection test.
- Phase 2: OMP schema and Solr config (`solr/conf/`) and a deploy script.

Indexing and search are not wired up yet, so OMP's own search is still
used.

## Settings

Administration → Site Settings → Plugins → "Royal Danish Library Solr
search" → Settings.

| Setting | Default |
|---|---|
| Solr URL | `http://127.0.0.1:8983/solr` |
| Core name | `omp` |
| Timeout (seconds) | `10` |

Opening the settings form tests the connection with the saved settings and
shows the Solr version and the number of indexed documents.

## Solr server

Solr runs standalone outside the OMP directory (Solr 9.11, Java 21). Keep
it listening on localhost only, as Solr has no authentication configured.
Server settings and install steps: `solr/solr.in.sh.example`.

| What | Where |
|---|---|
| Solr install | `/home/omp/solr` → `/home/omp/solr-9.11.0` |
| Solr home, index, logs | `/home/omp/solr-data` |
| Core | `omp` |

Start/stop: `/home/omp/solr/bin/solr start` / `stop`.

## Solr configuration

`solr/conf/` is the source of truth for the core's configuration. Never
edit the copy in the Solr home; change the files here and deploy:

```sh
plugins/generic/RDLSolrSearch/solr/deploy.sh                # install config, reload core
plugins/generic/RDLSolrSearch/solr/deploy.sh --reset-index  # also delete all documents
plugins/generic/RDLSolrSearch/solr/deploy.sh --help
```

The script backs up the running config (keeps the last 5) and restores it
if Solr rejects the new one. After a schema change, rebuild the index with
`php tools/rebuildSearchIndex.php`.

### Document fields

One document per published monograph.

| Field | Content |
|---|---|
| `id` | Submission id (string, unique key) |
| `submission_id`, `publication_id`, `press_id`, `series_id` | Ids for filtering |
| `work_type`, `date_published`, `locale` | Monograph data |
| `isbn`, `doi` | Identifiers, matched without hyphens/spaces and case-insensitively |
| `authors`, `chapter_authors` | Names (not language specific) |
| `<name>_<locale>` | Localized metadata, e.g. `title_da`, `abstract_en`, `chapter_title_da` |
| `<name>_ft_<locale>` | Full text of book files; searchable, not stored |
| `<name>_txt` | Text in a locale without its own analyzer |
| `title_sort` | Lowercased title for sorting |

Locale suffixes are OMP 3.4 locale codes (`da`, `en`, `fr_CA`, ...).
Fields that match nothing in the schema are dropped silently.

The `/search` handler uses edismax with all words required (`q.op=AND`),
returns only ids and score, and does not let users query fields directly
(`uf=-*`); the plugin chooses the searched fields with `qf`.

Language analyzers come from the PKP Lucene plugin. The Danish and
English ones were rewritten to stem before folding æ/ø/å and accents.

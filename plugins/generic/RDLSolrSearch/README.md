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
- Phase 3: metadata indexing, removal on unpublish/delete, full rebuild.
- Phase 4: OMP's search uses Solr, with OMP's own search as fallback.

## Search

The plugin answers OMP's search (`SubmissionSearch::retrieveResults`) from
Solr:

- All words must match, but they may match in different fields, e.g. an
  author's name plus a word from the title.
- Each language's fields use their own stemming and stopwords (Danish
  "historier" finds "historie"; "og" never blocks a match).
- Searched: titles, subtitles, keywords, subjects, disciplines, chapter
  titles/subtitles/abstracts, series titles, abstracts, coverage, type,
  affiliations, authors and chapter authors, ISBN and DOI (with or without
  hyphens), and full text (phase 5). Title matches count most.
- Locales searched: those of the press (site-wide: of all enabled presses).
- A press's search only finds that press's monographs.
- Sorting by relevance, title, first author, publication date and press
  works. Popularity sorting is not available in Solr and falls back to
  relevance.
- Users cannot query fields directly (`authors:x`) or use Solr local
  parameters; odd syntax never causes an error page.

If Solr fails (down, timeout, error), the search falls back to OMP's own
database search, the error is logged (prefix `RDLSolrSearch:`), and OMP's
search is used for the next 60 seconds, so visitors don't wait for a hanging
Solr on every search.

Note: the RdlOMPTheme search page shows only the first page of results
(20); it has no pagination links.

## Indexing

The plugin keeps Solr in sync through OMP hooks:

| OMP event | Solr |
|---|---|
| Monograph published, metadata changed (OMP search jobs) | Document added/replaced |
| Version unpublished | Reindexed if an older version is still published, otherwise removed |
| Submission deleted | Removed |
| `php tools/rebuildSearchIndex.php` | Full rebuild (see below) |

Only public monographs are indexed: the submission and its current
publication must be published and the press must be enabled. Only
approved publication formats contribute ISBNs and DOIs. Author
biographies are not indexed.

OMP keeps maintaining its own database index too, so its built-in search
remains available as a fallback. Indexing errors never block publishing;
they are written to the PHP error log (prefix `RDLSolrSearch:`) and are
repaired by a rebuild.

### Rebuild

```sh
php tools/rebuildSearchIndex.php              # Solr now, then OMP's own index (via queued jobs)
php tools/rebuildSearchIndex.php -solr-only   # Solr only
php tools/rebuildSearchIndex.php <press path> # Solr only, one press
```

The Solr rebuild runs immediately (it does not use the job queue), indexes
all public monographs and removes documents that should no longer be in
the index.

Changes that do not go through OMP's search jobs are picked up by the next
rebuild, e.g. renaming a series, disabling a press or approving a
publication format.

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
| `isbn`, `doi` | Identifiers, matched without hyphens and case-insensitively |
| `authors`, `chapter_authors` | Names (not language specific) |
| `<name>_<locale>` | Localized metadata, e.g. `title_da`, `abstract_en`, `chapter_title_da` |
| `<name>_ft_<locale>` | Full text of book files; searchable, not stored |
| `<name>_txt`, `<name>_ft_txt` | Metadata / full text in a locale without its own analyzer |
| `title_sort` | Lowercased title (without prefix) for sorting |
| `authors_sort` | First author, lowercased "family, given", for sorting |

Locale suffixes are OMP 3.4 locale codes (`da`, `en`, `fr_CA`, ...).
Fields that match nothing in the schema are dropped silently.

The `/search` handler uses edismax with all words required (`q.op=AND`),
returns only ids and score, and does not let users query fields directly
(`uf=-*`); the plugin chooses the searched fields with `qf`. Keep Solr's
default `sow=false`: it lets each language field apply its own stopwords.
All fields in `qf` must split the query on whitespace (as `isbn`/`doi` do),
otherwise edismax stops matching words across different fields.

Language analyzers come from the PKP Lucene plugin. The Danish and
English ones were rewritten to stem before folding æ/ø/å and accents.

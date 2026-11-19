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
- Phase 5: full text of open-access book files.
- Phase 6: snippets, facets, search suggestions, systemd service (and
  pagination in RdlOMPTheme).

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
  hyphens), and full text. Title matches count most; full text least.
- Locales searched: those of the press (site-wide: of all enabled presses).
- A press's search only finds that press's monographs.
- Sorting by relevance, title, first author, publication date and press
  works. Popularity sorting is not available in Solr and falls back to
  relevance.
- Users cannot query fields directly (`authors:x`) or use Solr local
  parameters; odd syntax never causes an error page.

### Snippets, facets and suggestions

The search page (RdlOMPTheme `frontend/pages/search.tpl`) gets a template
variable `rdlSolrSearch` from the plugin (see
`RDLSolrSearchPlugin::getTemplateData()`):

- **Snippets**: up to two per result, one from the description (abstract,
  chapter abstracts/titles) and one from inside the book, with the search
  words in `<mark>`. Snippets are escaped in PHP; `<mark>` is the only
  markup. The whole book is searched for a snippet (term offsets are in
  the index).
- **Author matches**: when the search words match an author name, the
  theme marks them in the result's author line (a regex from the plugin,
  applied to the escaped line). If no shown name matched, e.g. a chapter
  author or an author hidden behind "et al.", the matching names are
  shown as an extra snippet.
- **Facets**: press (site-wide search only), series and publication year,
  with counts. Request parameters `searchPress`, `searchSeries`,
  `searchYear`; a facet keeps listing its other values while one is
  selected. Facets with a single value are hidden.

**Suggestions**: while typing in any search box (`input[name="query"]`),
matching book titles (with authors) are listed; choosing one opens the
book. Words match by their beginning ("kong bø" finds "Kongernes bøger"),
æ/ø/å and accents are ignored. Within a press only its books are
suggested. Endpoint: `index.php/<press|index>/rdlsolrsuggest?q=...`
(JSON); script and style: `js/suggest.js`, `css/suggest.css`, added to
public pages by the plugin.

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
| Proof file uploaded, edited, deleted; format approved, made available, removed (published books) | OMP search job queued, which reindexes the book |
| `php tools/rebuildSearchIndex.php` | Full rebuild (see below) |

Only public monographs are indexed: the submission and its current
publication must be published and the press must be enabled. Only public
(approved and available) publication formats contribute ISBNs and DOIs.
Author biographies are not indexed.

### Full text

Full text comes only from files an anonymous visitor can download for free
(the same checks as the book's download page):

- a proof file of a publication format of the current, published publication,
- the format is approved and available and not a remote URL,
- the file is open access (price `0`),
- the press does not require a login to read monographs.

Files for sale, unapproved/unavailable formats, other file stages and
dependent files are never read. Note that OMP's own database index does
include all proof files.

| Type | Extraction |
|---|---|
| PDF | `pdftotext` (`/usr/bin/pdftotext`, max 120 s per file) |
| EPUB | (X)HTML chapters, in PHP |
| HTML, XML, plain text | In PHP |

Files missing from the files directory are skipped: background indexing
logs each one, a rebuild prints one count at the end. Other types (e.g.
Word) are skipped. At most 2 million characters per file
and 5 million per book are indexed. Full text is stored in Solr
(compressed) so results can show snippets from inside the book. A scanned PDF without a text layer adds no text.

Extracted text is cached gzipped in OMP's files directory under
`rdlSolrSearch/fulltext/`, by file id. Stored files never change (a new
revision gets a new id), so reindexing does not run `pdftotext` again. The
cache can be deleted at any time; it is rebuilt as needed.

OMP keeps maintaining its own database index too, so its built-in search
remains available as a fallback. Indexing errors never block publishing;
they are written to the PHP error log (prefix `RDLSolrSearch:`) and are
repaired by a rebuild.

### Rebuild

Run it as the user that runs OMP (the web server or PHP process), so it
can read the book files and the full-text cache stays writable for OMP's
background jobs. On a typical Apache server that is `www-data`
(`sudo -u www-data php tools/...`); on a development machine running
`php -S` it is your own user.

```sh
php tools/rebuildSearchIndex.php              # Solr now, then OMP's own index (via queued jobs)
php tools/rebuildSearchIndex.php -solr-only   # Solr only
php tools/rebuildSearchIndex.php <press path> # Solr only, one press
```

The Solr rebuild runs immediately (it does not use the job queue), indexes
all public monographs and removes documents that should no longer be in
the index. The first rebuild with full text runs `pdftotext` on every
open-access file and takes longer; later rebuilds use the cache.

Changes that do not go through OMP's search jobs or the event log are
picked up by the next rebuild, e.g. renaming a series or disabling a
press.

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

Run Solr as a system service, so it starts at boot and restarts after a
crash. `solr/solr-omp.service` runs it as user `omp` with the file limits
Solr recommends:

```sh
sudo cp plugins/generic/RDLSolrSearch/solr/solr-omp.service /etc/systemd/system/
/home/omp/solr/bin/solr stop -p 8983   # if it was started by hand
sudo systemctl daemon-reload
sudo systemctl enable --now solr-omp
systemctl status solr-omp
```

Without the service: `/home/omp/solr/bin/solr start` / `stop`.

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

Some schema changes are incompatible with documents already in the index,
e.g. storing or adding offsets to an existing field. Solr then rejects
updates with "cannot change field ... index options". Deploy with
`--reset-index` (empties the index; search finds nothing until the
rebuild) and rebuild.

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
| `fulltext_ft_<locale>` | Full text of open-access book files (file locale, else the book's); stored (compressed) with term offsets, for snippets |
| `<name>_txt`, `<name>_ft_txt` | Metadata / full text in a locale without its own analyzer |
| `title_sort` | Lowercased title (without prefix) for sorting |
| `authors_sort` | First author, lowercased "family, given", for sorting |
| `year` | Publication year, for the year facet |
| `suggest` | Titles and authors (copyField), matched by word beginnings, for suggestions |

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

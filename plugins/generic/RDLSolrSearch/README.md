# RDLSolrSearch

Site-wide OMP 3.4 plugin that replaces OMP's database search with a
standalone Solr core.

Solr runs as its own service and is never installed inside the OMP
directory. By default the plugin talks to `http://127.0.0.1:8983/solr`,
core `omp`.

## Status

Phase 1: plugin skeleton, site-wide settings, Solr client and a connection
test. Indexing and search are not wired up yet, so OMP's own search is
still used.

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

Solr is not bound to the plugin. Keep it listening on localhost only, as
Solr has no authentication by default.

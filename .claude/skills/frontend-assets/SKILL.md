---
name: frontend-assets
description: Add, upgrade or style front-end code in this Node-free Symfony app (AssetMapper importmap packages, the vendored FullCalendar global bundle, Bootstrap, design tokens, Classic-theme styling). Use when touching assets/, importmap.php, templates' scripts, or calendar styling.
---

# Front-end assets without Node

There is no npm, no bundler and no CDN. AssetMapper serves ES modules through an importmap; the
dependencies are committed so a build downloads nothing.

## Add or upgrade an importmap package (e.g. Bootstrap)

```bash
make console ARGS="importmap:require <package>@<version>"   # updates importmap.php + assets/vendor/
make console ARGS="importmap:outdated"
make console ARGS="importmap:update <package>"
make assets                                                 # importmap:install + asset-map:compile
```

Commit `importmap.php` and the changed files under `assets/vendor/` together.

## Upgrade FullCalendar

FullCalendar 7's ES modules break under an importmap (two copies of Preact), so the project ships the
vendor's **global bundles** in `assets/fullcalendar/`: `fullcalendar-all.global.min.js`,
`theme-classic.global.min.js`, `skeleton.min.css`, `theme-classic.css`, `theme-classic-palette.css`.
To upgrade, download the same files for the new version from the official release, replace them
as a set, and check:

- `assets/calendar/booking-calendar.js` still boots (no `plugins` option: the bundle registers every view);
- events, the "+N more" link and day clicks still work (the code attaches its own listener in
  `dayCellDidMount` and marks interactive elements with `data-calendar-interactive`);
- the re-skin still applies (the `--fc-classic-*` variables).

The PreToolUse hook blocks hand edits in `assets/fullcalendar/`; replacing the files is a
deliberate step to do with the user's approval.

## Styling rules

- Colours, type, radii and elevation are custom properties in `assets/styles/app.css`. Bootstrap and
  FullCalendar both read them. Add a token rather than a literal colour.
- The Classic theme renames classes to obfuscated `fc-classic-…`, so `.fc-button` and friends match
  nothing. Hook onto roles, `[data-date]` and real `button` elements.
- Text/background pairs must meet WCAG AA (4.5:1); measure, don't eyeball. `ink-300` is decoration only.

## Behaviour rules

- Forms and pagers work without JavaScript; scripts enhance them (`paged-results.js` swaps in the
  same Twig partial fetched with `X-Requested-With`).
- The customer picker is debounced and sequence-checked: a slow reply must never overwrite a newer one.
- Server messages (problem+json `detail`) are shown verbatim in the toast (`notifications.js`).

Check in a browser at http://localhost:8080 after `make assets`; in dev, AssetMapper serves files
through `index.php`, so a 404 under `/assets/` usually means a wrong logical path.

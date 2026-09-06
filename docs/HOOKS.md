# HOOKS.md

Every hook Debloater exposes for an extension, and what each one promises.

This is a contract. Debloater Pro reaches the free plugin through these and
nothing else, and `tests/Integration/ExtensionPointsTest.php` asserts each one
fires with the documented signature — so a refactor that quietly drops one
fails a test rather than silently breaking whatever depended on it.

Hooks not listed here are internal. There are a few (`debloater_tweak_options`,
`debloater_required_capability`) which exist for the plugin's own use and may
change without notice.

---

## What extensions may and may not do

The seams are shaped so that an extension can add **workflow around** the
engine and cannot become **part of** the engine.

An extension can read anything, be told when things happen, add text to the
dashboard, and point registry updates at a different HTTPS repository.

An extension cannot register a tweak, replace a service, alter a plan, skip a
recovery point, change a risk level, or put markup on Debloater's screen. There
is no hook for any of those, and their absence is deliberate rather than an
oversight: the argument that this plugin is safe to let near a live site rests
on there being exactly one implementation of the parts that touch it
(`BUILD-SPEC.md` §13 rule 15).

---

## Actions

### `debloater_loaded`

```php
do_action( 'debloater_loaded', \Debloater\Plugin $plugin );
```

Debloater has booted and its services can be reached. Fires once, late in
`register()`, after the REST routes and admin screen are hooked and before
anything has run.

The whole plugin is passed rather than a curated subset, because guessing in
advance which accessor an extension will want is how a hook ends up with six
more arguments a year later. Every accessor is a getter; there is no setter, so
an extension can read the resolver, the risk engine and the snapshot manager,
and can replace none of them.

**This is the entry point.** An extension should do its wiring here rather than
on `plugins_loaded`, because it is the earliest moment at which the free
plugin's services are guaranteed to exist.

---

### `debloater_scan_complete`

```php
do_action( 'debloater_scan_complete', \Debloater\Contracts\Run $run, \Debloater\Plugin $plugin );
```

A scan finished and its findings are stored. Fires for every scan whatever
started it — the dashboard, WP-CLI, or a schedule — so an extension watching for
change does not have to know which.

The run is already saved. This is a notification, not a filter: nothing done
here changes what was found.

`$run->payload['analysis']['findings']` holds the findings; `Plugin::findingsOf(
$run )` turns them back into `Finding` objects, discarding any that no longer
validate.

---

### `debloater_apply_complete`

```php
do_action(
    'debloater_apply_complete',
    \Debloater\Contracts\ApplyResult $result,
    \Debloater\Contracts\PreviewPlan $plan,
    \Debloater\Plugin $plugin
);
```

An apply finished, **whatever its outcome**. Fires for a clean apply, a
verified-with-warnings apply, and a rolled-back one alike.

Deliberately not success-only. An extension reporting on changes needs all
three, and a hook that fired only on success would quietly make rollbacks
invisible to exactly the report meant to explain them.

---

## Filters

### `debloater_dashboard_panels`

```php
apply_filters( 'debloater_dashboard_panels', array $panels ): array
```

Extra panels for the dashboard. Return an array of:

```php
array(
    'title' => 'What changed since the last scan',
    'rows'  => array(
        array( 'label' => 'New',      'value' => 'Emoji script loads on every page' ),
        array( 'label' => 'Resolved', 'value' => '2 400 expired transients' ),
    ),
)
```

**Text, not markup.** `Screen::sanitisePanels()` strips tags from every title,
label and value before the payload is written, and the React component renders
them as children, which escapes. Two independent reasons an extension cannot put
an element, a script or a style on this screen, and neither depends on the other
being right.

A malformed panel is dropped rather than rendered badly. At most five panels are
shown: a dashboard is a place to look, not a place to scroll, and an extension
with more than that to say wants its own screen — where it is responsible for
its own escaping.

---

### `debloater_registry_origin`

```php
apply_filters( 'debloater_registry_origin', string $base ): string
```

Where registry updates are fetched from. Return a base URL with no trailing
slash.

Exists so a Pro priority channel can point at a different repository without the
free plugin knowing anything about channels.

**It cannot relax anything.** `RegistryOrigin` refuses a base that is not HTTPS
and rejects path segments it does not like, so a base this filter cannot
construct is a base nothing fetches from — and a filter that returns something
unusable falls back to the shipped origin rather than switching updates off. The
manifest from wherever it points still has to pass Ed25519 signature
verification, the same traversal checks and the same size and count ceilings
before a single file is written.

It also does not turn updates on. The registry fetch is opt-in
(`BUILD-SPEC.md` §13 rule 9) and stays opt-in.

---

### `debloater_required_capability`

```php
apply_filters( 'debloater_required_capability', string $capability ): string
```

Which core capability `debloater_manage` maps to. Defaults to `manage_options`.

Listed for completeness rather than as an invitation. Lowering it hands the
ability to rewrite what loads on every request — and to delete rows — to
whoever holds the weaker capability, and nothing downstream will second-guess
that choice.

---

## URL contracts

Not hooks, but depended on from outside this repository all the same, and so
held to the same promise: they do not change without a note here.

### `?page=debloater&debloater_profile=<id>`

Opens the admin screen with that profile's changes ticked in the ordinary
preview. Pro's Profiles panel links here rather than applying anything itself.

What it carries is an **id**, never a selection. The screen looks the id up in
its own `ProfileStore`; an id naming nothing opens nothing. So the most a
crafted link can do is show somebody a preview of changes this site was already
offering them — and the preview is where anything is decided, issuing its own
confirmation token exactly as it does when the button was on the page
(`BUILD-SPEC.md` §13 rule 8).

A link cannot apply anything, and nothing may be added here that would make
that sentence need qualifying.

---

## Adding a hook

A new extension point needs, in the same commit: the hook, its entry here, and a
test in `ExtensionPointsTest` asserting it fires with the documented arguments.

A hook without a test is a promise nobody is keeping.

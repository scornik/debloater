# SCORING.md

**Rubric version 2.0** · Phase 12 · 2026-09-03

This document is the public account of how the Debloat Score and the confidence
figures are calculated. It is versioned, and the version is shown in the
interface next to the score, so a number can always be traced back to the rules
that produced it. Changing anything here means bumping the version.

---

## What the Debloat Score is, and is not

The Debloat Score measures **configuration and maintenance**: how much of what
WP Debloat looked at is in a state it would suggest changing.

It is **not a performance benchmark**. It says nothing about how fast the site
is, and it must never be presented as if it did. A site can score 100 and be
slow; a site can score 60 and be fast. Measuring the effect of a change is the
Meter's job, and the Meter reports before-and-after counts in real units —
requests, bytes, rows — never a time.

There is deliberately no Performance sub-score (`BUILD-SPEC.md` §1, locked
decision 1).

---

## Sub-scores

Version 2.0 scores six categories:

| Sub-score | What it covers |
|---|---|
| **WordPress** | Core output and behaviour: the generator tag, emoji script, embeds, Heartbeat |
| **Configuration** | Settings that affect the whole site: the file editor, XML-RPC |
| **Database** | What has accumulated: revisions, expired transients, trash, orphaned metadata |
| **Plugins** | What is installed and what it implies |
| **Maintenance** | Housekeeping that runs on its own: self-pingbacks, cron |
| **Admin** | What the people who run the site have to look at: notices, dashboard widgets |

One category exists and is **not scored in this version**:

- **Assets** — arrives after the asset scan in Phase 13, which is the first
  point at which the findings can be based on real page fetches rather than
  inference.

Findings in an unscored category are still produced, still shown, and reported
in `unscored_categories` so the interface can say "2 findings in Assets, not yet
part of the score". They are not silently dropped.

### Why Admin only became a sub-score now

It was named in version 1.0 and left unscored, which was right at the time: a
sub-score over a category with no findings in it is a perfect ten awarded for
nothing, and it would have pulled the headline up on every site. Phase 12 gave
the category rules and evidence, so it now scores like the others.

### What Admin scoring does not do

Admin findings are about interruption, not speed, and the score has to be as
careful there as everywhere else.

- The dashboard-widget finding is `info` and costs nothing. Which widgets are
  worth having is a question about the person, not about the site, and a score
  that dropped because somebody kept a widget they read every morning would be
  measuring the wrong thing.
- The plugin-notice finding is `low`, not high, even though the change it
  proposes is `medium` risk. Severity is how much this costs the site; risk is
  how much the fix could cost. They are separate axes and this is the case that
  shows why.
- Nothing in Admin penalises a site for a notice WP Debloat cannot offer to
  hide. Penalising something the user has no way to improve from here would be
  showing a number they cannot move.

### Calculation

```
sub-score = 100 − min(100, Σ penalty(finding))
```

over the findings in that category, and

```
headline = mean(sub-scores)
```

The mean is unweighted. Version 1 has no evidence for saying one category
matters more than another, and inventing weights would be inventing a claim.

### Penalty by severity

| Severity | Penalty |
|---|---|
| info | 0 |
| low | 4 |
| medium | 10 |
| high | 20 |

Two rules constrain the sum:

- **Each finding id counts once.** A rule that somehow fires twice cannot double
  its own weight.
- **A `dont_touch` finding contributes nothing.** This one matters. Penalising a
  site for a configuration WP Debloat has decided *not* to change would show the
  user a number they cannot improve without ignoring our own advice. A refusal
  is a result, not a debt.

---

## Confidence

Confidence answers a different question from risk, and the two are never
combined into one figure.

- **Risk**: how bad would it be if this change went wrong?
- **Confidence**: how sure are we that this reading of the site is correct?

A change can be low-risk and low-confidence (we are not certain it applies here,
but if we are wrong the cost is small), or high-risk and high-confidence (we are
sure, and it still needs care).

```
confidence = base × penalty₁ × penalty₂ × …
```

The base is declared by the rule, for the ideal case: a site we can see clearly,
with nothing between us and the truth. Every penalty is a specific reason the
view is less clear than that.

| Penalty | Multiplier | Why |
|---|---|---|
| Unknown host | × 0.95 | Managed hosts apply their own optimisations at the server level. On a host we recognise, we know what is already handled; on one we do not, our reading may be incomplete. |
| Cache plugin present | × 0.95 | A page cache means what a visitor receives is not necessarily what WordPress just generated, so a front-end observation may describe a page nobody is served. |
| Detected dependents | × 0.90 each, compounding, up to 3 | Every component declaring a dependency on what a tweak would change is another thing that could break in a way we have not modelled. Two dependents are meaningfully worse than one, so it compounds. |
| Custom mu-plugins | × 0.90 | Code in `mu-plugins` is site-specific, invisible to the registry, and runs before everything else. It is the most common reason a site behaves unlike any site we have seen. |

Two bounds:

- **The dependent penalty stops compounding at three.** Past that the message is
  already "several things depend on this"; driving confidence towards zero would
  turn a caution into a refusal, and a refusal is what `dont_touch` is for.
- **Confidence never falls below 0.30** through penalties alone. Below that
  figure the honest answer is not a low number but a decision not to recommend
  the change at all.

Confidence is rounded to two decimals, so the same site always prints the same
figure.

Our own mu-plugin loader does not count as custom code: it is ours, we know
exactly what it does, and penalising confidence for installing WP Debloat would
be absurd.

---

## Don't touch

A finding becomes `dont_touch` for one of two reasons, and always carries the
reason in `decision_reason`.

**A declared dependency would be removed.** A compatibility rule says a component
present on this site depends on a capability, and the tweak would take that
capability away. The refusal names the dependent.

**The site's circumstances make it wrong here.** The change is defensible in
general and not on this site. Version 1 has one such rule: Heartbeat is not
slowed on a WooCommerce store where two or more people edited content in the last
week. Heartbeat is what warns them they are about to overwrite each other, and
what keeps a checkout session from expiring mid-order.

### Removal versus effect

A capability dependency only refuses a change that would **remove** the
capability. A change that alters its behaviour without removing it counts
towards `dependencies_detected` — lowering confidence — and does not refuse.

This distinction is load-bearing. WooCommerce declares a dependency on
`heartbeat`, and it is a real one. But `core.heartbeat_interval` does not remove
Heartbeat; it slows it from 15 seconds to 60. Treating that as a removal would
refuse a reasonable change on every WooCommerce site in existence, on the
strength of a dependency that is still satisfied afterwards.

---

## Changelog

### 2.0 — Phase 12

Admin becomes a sub-score, so the headline is now the mean of six rather than
five. Nothing else changed: the severity penalties, the confidence penalties and
the `dont_touch` rules are the same as in 1.0.

The practical effect is that a site with admin findings scores slightly
differently from the same site under 1.0. That is why this is a version bump
rather than an edit — a score is only meaningful next to the rubric that
produced it, and the version travels with it.

### 1.0 — Phase 3

Initial rubric. Five sub-scores, severity penalties of 0/4/10/20, unweighted
mean, four confidence penalties, and the two `dont_touch` sources above.

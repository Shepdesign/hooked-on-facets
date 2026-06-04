# Facet Type: Ask

> 💬 **Shipped (experimental).**

A conversational, multi-turn natural-language facet. Shoppers type what they want in plain English; the model returns each constraint it heard as a removable chip. Tap × on any chip to drop it. Type another turn to refine. Powered by Anthropic's Claude — **bring your own key**.

## what it is

A "view" facet (no source of its own) that renders a chunky input with a sparkle icon and, after the first submit, a chip row showing every constraint the AI extracted from the conversation so far. Each chip maps 1:1 to a filter on an underlying facet — removing a chip drops both the chip *and* the filter, and trims the model's next-turn context.

It's the same machine as a normal facet, just with a different *input*.

## what makes it different from a search box

Most NL search swallows the query and shows results — shoppers can't tell what the AI actually heard. Ask flips that:

| | Plain AI search | Ask |
|---|---|---|
| What the AI heard | Invisible | Visible chips, one per constraint |
| Correcting a misread | Retype the whole thing | Tap × on the wrong chip |
| Adding to a query | Retype the whole thing | Just type the addition |
| Tightening / broadening | New search | "...and rated 4+" extends the prior state |

## when to use it

- Shoppers who describe what they want in language, not facets
- Long-tail catalogs where the right filter combination isn't obvious
- A11y / mobile audiences for whom typing one sentence beats tapping ten chips
- As a complement to traditional facets, not a replacement

## when not to use it

- Free-text search across product names/descriptions → use [Search](Facet-Type-Search) (cheaper, no API key)
- Sites without a budget for per-query API costs → use [Search](Facet-Type-Search)
- Single-language stores where shoppers already know the jargon — traditional facets are faster

## configuration

A view facet with `display: "ask"`.

```json
{
  "name": "ai",
  "kind": "view",
  "display": "ask",
  "label": "Ask",
  "settings": {
    "placeholder": "Try: comfy red shoes under $50"
  }
}
```

### options

| Field | Values | What |
|---|---|---|
| `kind` | `"view"` | Required |
| `display` | `"ask"` | Required |
| `settings.placeholder` | string | Optional placeholder text; defaults to "Describe what you're looking for…" |
| `source` | — | Not used |

## interaction model

| Action | Result |
|---|---|
| Type a turn → submit | POST `{query, prior_state}` → returns full updated state → chips render → filters apply |
| Tap × on a chip | Filter dropped locally; chip removed; next turn inherits the trimmed state |
| Type a refining turn ("...also under $50") | Extends prior state; chips grow |
| Type a releasing turn ("any color") | Releases the color constraint; chip removed |
| "↺ Start over" | Clears all chips and filters; conversation resets |

The conversation lives in memory on the page — reloading clears the chips but keeps the filters that were already applied (because those live in the URL, like any normal facet).

## bring your own key

The API key lives in `wp_options['hof_ai']['api_key']` and is set via **HOF → Ask** in the WP admin. It **never reaches the browser**. The REST endpoint that calls Claude reads it server-side per request.

| Key fact | Why it matters |
|---|---|
| Per-site key | Each site owner pays for their own usage |
| Server-side only | Browser never sees the key; no leak risk in DevTools or bundle |
| Cleared with one click | Admin UI has a Clear button that wipes the option |

Pricing is owner-facing only — typical cost is ~$0.0001–$0.0005 per turn at current Claude Haiku 4.5 pricing.

## under the hood

| Detail | Value |
|---|---|
| REST endpoint | `POST /wp-json/hof/v1/ask` with `{query, prior_state}` |
| Default model | `claude-haiku-4-5` (overridable via `hof_ai_model` filter) |
| Output mode | Tool use — `tool_choice: {type: "tool", name: "apply_facet_filters"}` |
| Tool schema | Built dynamically from configured facets — `enum` for taxonomies/meta strings, range objects for range facets |
| Prompt cache | `cache_control: ephemeral` on the system block — facet schema is the cached prefix |
| Conversation model | **Stateless on the server.** Frontend always sends the current `prior_state`; server returns the full updated state. No session table to manage. |
| Timeout | 6 seconds, one retry on 429/500/529 |
| Tool name | `apply_facet_filters` |

The model is instructed to return the **full resulting state**, not a delta — the caller replaces its state with whatever the tool call returns. New turns extend, tighten, broaden, or release as appropriate.

## URL state

Ask writes through to whichever facets the model fills. Like 2D slider, it has no URL key of its own.

```text
?_hof_color=red,burgundy&_hof_price=0,50
```

Same wire format as user-driven filters. Deep links work; switching display modes doesn't break them.

## errors users see

The endpoint friendly-maps Anthropic's jargon so shoppers never see raw API errors:

| Real error | What the user sees |
|---|---|
| `credit_balance`, `insufficient_funds`, billing | "Ask isn't available right now." |
| Missing key (admin hasn't configured one) | "Ask isn't available right now." |
| Timeout / 5xx | "Ask is busy. Try again in a moment." |
| Empty / no-match turn | "Didn't catch any constraints. Try mentioning a color, category, or price." |

Verbose errors go to the PHP error log for the site owner.

## planned PHP filters

```php
apply_filters( 'hof_ai_model',         'claude-haiku-4-5' );
apply_filters( 'hof_ai_system_prompt', $prompt, $facets );
apply_filters( 'hof_ai_tool_schema',   $schema, $facets );
apply_filters( 'hof_ai_request_body',  $body, $facets, $query, $prior_state );
apply_filters( 'hof_ai_apply_filters', $applied, $tool_input, $facets );
```

## see also

- [Search](Facet-Type-Search) — non-AI free-text search alternative
- [[Core Concepts|Core-Concepts]] — view facets, URL state
- [[Architecture]] — where the Ask request fits in the data flow

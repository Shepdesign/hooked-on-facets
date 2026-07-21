import { useEffect, useState } from 'react';
import { IconSearch, IconCheck } from '@tabler/icons-react';

// SEO controls for faceted (?hof[*]) views: canonical to the clean URL,
// noindex of multi-facet combos, and an active-filters title suffix. Settings
// persist to the `hof_seo` option via /seo-settings.

const FIELDS = [
    {
        key: 'manage_canonical',
        type: 'bool',
        label: 'Canonical to the clean URL',
        help: 'Point filtered views at the unfiltered base URL so ranking signals consolidate. Automatically skipped if Yoast, Rank Math, AIOSEO, or SEOPress is active (they own canonical).',
    },
    {
        key: 'noindex_combos',
        type: 'bool',
        label: 'Noindex stacked filters',
        help: 'Add noindex,follow once enough facets are combined, so the combinatorial long tail stays out of the index while links are still crawled.',
    },
    {
        key: 'noindex_threshold',
        type: 'int',
        label: 'Noindex threshold',
        help: 'Number of active facets at which noindex kicks in. 2 keeps single-facet landing pages indexable and noindexes everything broader.',
        min: 1,
    },
    {
        key: 'title_suffix',
        type: 'bool',
        label: 'Append filters to the page title',
        help: 'Adds "· Brand: Acme, Color: Red" to the document title so an indexed single-facet page reads meaningfully in search results.',
    },
];

export default function SeoSettings({ bootstrap }) {
    const [loading, setLoading] = useState(true);
    const [settings, setSettings] = useState(null);
    const [prettyUrls, setPrettyUrls] = useState(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');

    const restUrl = bootstrap?.restUrl || '';
    const nonce = bootstrap?.nonce || '';

    useEffect(() => {
        (async () => {
            try {
                const res = await fetch(`${restUrl}seo-settings`, { headers: { 'X-WP-Nonce': nonce } });
                const data = await res.json();
                setSettings(data.settings || {});
                setPrettyUrls(data.pretty_urls || null);
            } catch (e) {
                setError('Could not load SEO settings.');
            } finally {
                setLoading(false);
            }
        })();
    }, [restUrl, nonce]);

    const save = async (next) => {
        setSaving(true);
        setError('');
        setSuccess('');
        try {
            const res = await fetch(`${restUrl}seo-settings`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify({ settings: next }),
            });
            if (!res.ok) {
                const body = await res.json().catch(() => ({}));
                throw new Error(body?.error || `HTTP ${res.status}`);
            }
            const data = await res.json();
            setSettings(data.settings || {});
            setSuccess('Saved.');
        } catch (e) {
            setError(e.message || 'Save failed.');
        } finally {
            setSaving(false);
        }
    };

    // Optimistic local update; persist the merged object.
    const update = (key, value) => {
        const next = { ...settings, [key]: value };
        setSettings(next);
        save(next);
    };

    // Pretty URLs persist through the same route but as their own option, so
    // a rewrite flush only fires when these two fields change.
    const updatePretty = (key, value) => {
        const next = { ...prettyUrls, [key]: value };
        setPrettyUrls(next);
        (async () => {
            setSaving(true);
            setError('');
            setSuccess('');
            try {
                const res = await fetch(`${restUrl}seo-settings`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify({ settings, pretty_urls: { enabled: next.enabled, base: next.base } }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                setPrettyUrls(data.pretty_urls || next);
                setSuccess('Saved. Permalinks refresh on your next visit to any admin page.');
            } catch (e) {
                setError(e.message || 'Save failed.');
            } finally {
                setSaving(false);
            }
        })();
    };

    if (loading || !settings) {
        return (
            <div className="hof-ai-settings">
                <h2 className="hof-ai-settings-title">SEO</h2>
                <p className="hof-ai-settings-status">Loading…</p>
            </div>
        );
    }

    return (
        <div className="hof-ai-settings">
            <header className="hof-ai-settings-header">
                <span className="hof-ai-settings-icon"><IconSearch size={20} stroke={1.75} /></span>
                <div>
                    <h2 className="hof-ai-settings-title">SEO</h2>
                    <p className="hof-ai-settings-sub">
                        Faceted URLs spawn near-duplicate, crawl-bloating pages. These controls keep the
                        filtered long tail tidy — general SEO plugins don't understand the <code>?hof[*]</code>
                        query shape, so Hooked on Facets manages it.
                    </p>
                </div>
            </header>

            <section className="hof-ai-settings-section">
                <h3 className="hof-ai-settings-section-title">Crawl &amp; indexing</h3>
                {FIELDS.map((f) => (
                    <div key={f.key} className="hof-ai-settings-input-row">
                        {f.type === 'bool' ? (
                            <label className="hof-ai-settings-label">
                                <input
                                    type="checkbox"
                                    checked={!!settings[f.key]}
                                    disabled={saving}
                                    onChange={(e) => update(f.key, e.target.checked)}
                                />
                                {' '}{f.label}
                            </label>
                        ) : (
                            <label className="hof-ai-settings-label">
                                {f.label}
                                {' '}
                                <input
                                    type="number"
                                    min={f.min ?? 1}
                                    className="hof-ai-settings-input"
                                    style={{ width: '5rem', display: 'inline-block' }}
                                    value={settings[f.key] ?? ''}
                                    disabled={saving}
                                    onChange={(e) => update(f.key, Math.max(f.min ?? 1, parseInt(e.target.value, 10) || (f.min ?? 1)))}
                                />
                            </label>
                        )}
                        <p className="hof-ai-settings-row-sub">{f.help}</p>
                    </div>
                ))}
                {error && <p className="hof-ai-settings-msg hof-ai-settings-msg--error">{error}</p>}
                {success && (
                    <p className="hof-ai-settings-msg hof-ai-settings-msg--ok">
                        <IconCheck size={14} stroke={2} /> {success}
                    </p>
                )}
            </section>

            {prettyUrls && (
                <section className="hof-ai-settings-section">
                    <h3 className="hof-ai-settings-section-title">Pretty faceted URLs</h3>
                    {!prettyUrls.available && (
                        <p className="hof-ai-settings-msg hof-ai-settings-msg--error">
                            Pretty URLs need non-plain permalinks. Set a permalink structure under
                            Settings → Permalinks first.
                        </p>
                    )}
                    {prettyUrls.warning && (
                        <p className="hof-ai-settings-msg hof-ai-settings-msg--error">{prettyUrls.warning}</p>
                    )}
                    <div className="hof-ai-settings-input-row">
                        <label className="hof-ai-settings-label">
                            <input
                                type="checkbox"
                                checked={!!prettyUrls.enabled}
                                disabled={saving || !prettyUrls.available}
                                onChange={(e) => updatePretty('enabled', e.target.checked)}
                            />
                            {' '}Rewrite filters into the path
                        </label>
                        <p className="hof-ai-settings-row-sub">
                            Turns <code>/shop/?hof[brand]=nike</code> into <code>/shop/filter/brand/nike/</code> on
                            the shop and product archives — clean, crawlable, shareable. Legacy query URLs
                            301-redirect to the pretty form.
                        </p>
                    </div>
                    <div className="hof-ai-settings-input-row">
                        <label className="hof-ai-settings-label">
                            URL segment
                            {' '}
                            <input
                                type="text"
                                className="hof-ai-settings-input"
                                style={{ width: '8rem', display: 'inline-block' }}
                                value={prettyUrls.base ?? 'filter'}
                                disabled={saving || !prettyUrls.available}
                                onBlur={(e) => updatePretty('base', e.target.value || 'filter')}
                                onChange={(e) => setPrettyUrls({ ...prettyUrls, base: e.target.value })}
                            />
                        </label>
                        <p className="hof-ai-settings-row-sub">
                            The reserved path segment, default <code>filter</code>. Change it only if a real
                            category or page on your store is literally named “filter”.
                        </p>
                    </div>
                </section>
            )}
        </div>
    );
}
